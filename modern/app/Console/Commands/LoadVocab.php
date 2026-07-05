<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load an OHDSI Athena vocabulary release into Postgres.
 *
 * Staged: bulk-COPY into *_stg tables, prune/validate, then swap in inside a
 * single transaction (Postgres DDL is transactional), so the app serves the
 * old vocabulary until the swap commits.
 *
 * The directory is resolved inside the *database* container (server-side
 * COPY): real releases under /vocab_data/<dir>, the dev fixture at
 * /fixtures/athena_sample.
 *
 *   php artisan comet:load-vocab /fixtures/athena_sample --by=dev
 *   php artisan comet:load-vocab /vocab_data/athena_2026q2 --by="J. Mapper"
 */
class LoadVocab extends Command
{
    protected $signature = 'comet:load-vocab {dir : directory as seen by the Postgres container} {--by=cli : who is loading}';

    protected $description = 'Load an Athena vocabulary download into Postgres (staged + atomic swap)';

    /** Relationship types COMET needs for replacement resolution. */
    private const KEPT_RELATIONSHIPS = [
        'Maps to', 'Mapped from', 'Concept replaced by', 'Concept poss_eq to', 'Concept same_as to',
    ];

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/');

        $this->info("Creating staging tables...");
        DB::statement('DROP TABLE IF EXISTS concepts_stg, concept_synonyms_stg, concept_relationships_stg, vocabularies_stg');
        DB::statement('CREATE TABLE concepts_stg (LIKE concepts INCLUDING DEFAULTS)');
        DB::statement('CREATE TABLE concept_synonyms_stg (LIKE concept_synonyms INCLUDING DEFAULTS)');
        DB::statement('CREATE TABLE concept_relationships_stg (LIKE concept_relationships INCLUDING DEFAULTS)');
        DB::statement('CREATE TABLE vocabularies_stg (LIKE vocabularies INCLUDING DEFAULTS)');

        // Athena files are tab-delimited with a header and no quoting; the
        // \x01 QUOTE trick keeps embedded double-quotes literal.
        $copyOpts = "WITH (FORMAT csv, DELIMITER E'\\t', HEADER true, QUOTE E'\\x01', NULL '')";

        $this->info("Bulk-loading from $dir (server-side COPY)...");
        try {
            DB::statement("COPY concepts_stg (concept_id, concept_name, domain_id, vocabulary_id, concept_class_id, standard_concept, concept_code, valid_start_date, valid_end_date, invalid_reason) FROM '$dir/CONCEPT.csv' $copyOpts");
            DB::statement("COPY concept_synonyms_stg (concept_id, concept_synonym_name, language_concept_id) FROM '$dir/CONCEPT_SYNONYM.csv' $copyOpts");
            DB::statement("COPY concept_relationships_stg (concept_id_1, concept_id_2, relationship_id, valid_start_date, valid_end_date, invalid_reason) FROM '$dir/CONCEPT_RELATIONSHIP.csv' $copyOpts");
            DB::statement("COPY vocabularies_stg (vocabulary_id, vocabulary_name, vocabulary_reference, vocabulary_version, vocabulary_concept_id) FROM '$dir/VOCABULARY.csv' $copyOpts");
        } catch (\Throwable $e) {
            $this->error('COPY failed: '.$e->getMessage());
            $this->line('The path must exist inside the Postgres container (/vocab_data/... or /fixtures/...).');

            return self::FAILURE;
        }

        $counts = [
            'concepts' => DB::table('concepts_stg')->count(),
            'synonyms' => DB::table('concept_synonyms_stg')->count(),
            'relationships' => DB::table('concept_relationships_stg')->count(),
            'vocabularies' => DB::table('vocabularies_stg')->count(),
        ];
        foreach ($counts as $k => $v) {
            $this->line(sprintf('  %-14s %d rows', $k, $v));
        }
        if ($counts['concepts'] < 1 || $counts['vocabularies'] < 1) {
            $this->error('Aborting: empty CONCEPT or VOCABULARY staging table.');

            return self::FAILURE;
        }

        $this->info('Pruning relationships to the kept set...');
        $placeholders = implode(',', array_fill(0, count(self::KEPT_RELATIONSHIPS), '?'));
        $removed = DB::delete(
            "DELETE FROM concept_relationships_stg WHERE relationship_id NOT IN ($placeholders) OR invalid_reason IS NOT NULL",
            self::KEPT_RELATIONSHIPS
        );
        $this->line("  removed $removed rows");

        $bad = DB::table('concepts_stg')->whereRaw("valid_start_date !~ '^[0-9]{8}$'")->count();
        if ($bad > 0) {
            $this->error("Aborting: $bad concept rows have malformed valid_start_date.");

            return self::FAILURE;
        }

        $this->info('Indexing staging tables (before swap, so the new tables arrive indexed)...');
        DB::statement('ALTER TABLE concepts_stg ADD PRIMARY KEY (concept_id)');
        DB::statement('CREATE INDEX c_stg_code ON concepts_stg (concept_code)');
        DB::statement('CREATE INDEX c_stg_vocab ON concepts_stg (vocabulary_id)');
        DB::statement('CREATE INDEX c_stg_domain ON concepts_stg (domain_id)');
        DB::statement('CREATE INDEX c_stg_trgm ON concepts_stg USING gin (lower(concept_name) gin_trgm_ops)');
        DB::statement('CREATE INDEX s_stg_cid ON concept_synonyms_stg (concept_id)');
        DB::statement('CREATE INDEX s_stg_trgm ON concept_synonyms_stg USING gin (lower(concept_synonym_name) gin_trgm_ops)');
        DB::statement('ALTER TABLE concept_relationships_stg ADD PRIMARY KEY (concept_id_1, concept_id_2, relationship_id)');
        DB::statement('ALTER TABLE vocabularies_stg ADD PRIMARY KEY (vocabulary_id)');

        $release = DB::table('vocabularies_stg')->where('vocabulary_id', 'None')->value('vocabulary_version')
            ?: 'unknown ('.now()->toDateString().')';

        $this->info("Swapping in (release: $release)...");
        DB::transaction(function () {
            DB::statement('DROP TABLE concepts, concept_synonyms, concept_relationships, vocabularies');
            DB::statement('ALTER TABLE concepts_stg RENAME TO concepts');
            DB::statement('ALTER TABLE concept_synonyms_stg RENAME TO concept_synonyms');
            DB::statement('ALTER TABLE concept_relationships_stg RENAME TO concept_relationships');
            DB::statement('ALTER TABLE vocabularies_stg RENAME TO vocabularies');
            // canonical index names for the next run's LIKE/CREATE
            DB::statement('ALTER INDEX c_stg_code RENAME TO concepts_code_idx');
            DB::statement('ALTER INDEX c_stg_vocab RENAME TO concepts_vocab_idx');
            DB::statement('ALTER INDEX c_stg_domain RENAME TO concepts_domain_idx');
            DB::statement('ALTER INDEX c_stg_trgm RENAME TO concepts_name_trgm');
            DB::statement('ALTER INDEX s_stg_cid RENAME TO concept_synonyms_cid_idx');
            DB::statement('ALTER INDEX s_stg_trgm RENAME TO concept_synonyms_name_trgm');
        });

        DB::table('vocab_meta')->insert([
            'athena_release' => $release,
            'loaded_by' => $this->option('by'),
            'concept_count' => $counts['concepts'],
            'loaded_at' => now(),
        ]);

        $this->info("Done. Vocabulary release '$release' is live ({$counts['concepts']} concepts).");
        $this->line('Next: review the vocabulary impact report for maps whose targets changed.');

        return self::SUCCESS;
    }
}
