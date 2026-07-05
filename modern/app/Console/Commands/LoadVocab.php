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

        // Optional custom extensions (e.g. Canadian ICD-10-CA / CCI / SNOMED CT-CA)
        // appended into the SAME staging tables, so they become part of this
        // atomic vintage and are re-applied on every refresh. See
        // docs/vocab-refresh.md ("Canadian (BC) vocabulary extensions").
        $customConcepts = $this->loadCustom($dir, $copyOpts);

        $counts = [
            'concepts' => DB::table('concepts_stg')->count(),
            'synonyms' => DB::table('concept_synonyms_stg')->count(),
            'relationships' => DB::table('concept_relationships_stg')->count(),
            'vocabularies' => DB::table('vocabularies_stg')->count(),
        ];
        foreach ($counts as $k => $v) {
            $this->line(sprintf('  %-14s %d rows', $k, $v));
        }
        if ($customConcepts > 0) {
            $this->line("  (includes $customConcepts custom/extension concept rows)");
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

    /**
     * Append optional custom-extension files (same 4 shapes, `_CUSTOM` suffix)
     * into the staging tables so they ride the same atomic swap and are
     * re-applied on every refresh. Custom concepts must use concept_id >= 2e9
     * (OHDSI convention) to avoid colliding with Athena-managed concepts.
     *
     * @return int number of custom concept rows loaded
     */
    private function loadCustom(string $dir, string $copyOpts): int
    {
        $files = [
            'CONCEPT_CUSTOM.csv' => ['concepts_stg', '(concept_id, concept_name, domain_id, vocabulary_id, concept_class_id, standard_concept, concept_code, valid_start_date, valid_end_date, invalid_reason)'],
            'CONCEPT_SYNONYM_CUSTOM.csv' => ['concept_synonyms_stg', '(concept_id, concept_synonym_name, language_concept_id)'],
            'CONCEPT_RELATIONSHIP_CUSTOM.csv' => ['concept_relationships_stg', '(concept_id_1, concept_id_2, relationship_id, valid_start_date, valid_end_date, invalid_reason)'],
            'VOCABULARY_CUSTOM.csv' => ['vocabularies_stg', '(vocabulary_id, vocabulary_name, vocabulary_reference, vocabulary_version, vocabulary_concept_id)'],
        ];

        $loadedAny = false;
        foreach ($files as $file => [$table, $columns]) {
            // COPY runs server-side in the db container, so we can't stat the
            // file from the app container. Attempt it; a missing file is a
            // clean skip, anything else (malformed data) fails loudly.
            try {
                DB::statement("COPY $table $columns FROM '$dir/$file' $copyOpts");
                $this->info("Loaded custom extension: $file");
                $loadedAny = true;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'could not open file') || str_contains($e->getMessage(), 'No such file')) {
                    continue; // extension file not provided — fine
                }
                throw $e;
            }
        }

        if (! $loadedAny) {
            return 0;
        }

        // Guardrail: custom concepts should be in the 2-billion range.
        $low = DB::table('concepts_stg')
            ->where('concept_id', '<', 2000000000)
            ->where('concept_id', '>=', 1000000000) // heuristic: flag obvious mistakes, Athena ids are lower
            ->count();
        if ($low > 0) {
            $this->warn("  note: $low staged concept(s) sit in [1e9, 2e9) — custom concepts should use concept_id >= 2,000,000,000 to avoid Athena collisions.");
        }

        return (int) DB::table('concepts_stg')->where('concept_id', '>=', 2000000000)->count();
    }
}
