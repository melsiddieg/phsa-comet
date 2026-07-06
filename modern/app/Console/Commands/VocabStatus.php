<?php

namespace App\Console\Commands;

use App\Services\ConceptSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "Did the vocabulary load work?" readout: current release, per-vocabulary
 * concept counts, table sizes, the presence of the search indexes, and a live
 * sample query. Run after comet:load-vocab.
 *
 *   php artisan comet:vocab-status
 *   php artisan comet:vocab-status --search="blood pressure"
 */
class VocabStatus extends Command
{
    protected $signature = 'comet:vocab-status {--search= : run a sample search to prove the engine works}';

    protected $description = 'Report the loaded OMOP vocabulary and verify search is wired up';

    public function handle(ConceptSearch $search): int
    {
        $meta = DB::table('vocab_meta')->latest('id')->first();
        if (! $meta) {
            $this->warn('No vocabulary loaded yet (vocab_meta is empty). Run comet:load-vocab.');

            return self::SUCCESS;
        }

        $this->info("Vocabulary release: {$meta->athena_release}");
        $this->line("  loaded {$meta->loaded_at} by {$meta->loaded_by}");
        $this->newLine();

        // Table sizes
        $this->line('Row counts:');
        foreach (['concepts', 'concept_synonyms', 'concept_relationships', 'concept_ancestors', 'vocabularies'] as $t) {
            $this->line(sprintf('  %-24s %s', $t, number_format(DB::table($t)->count())));
        }
        $this->newLine();

        // Per-vocabulary + standard breakdown
        $this->line('Concepts by vocabulary (top 25):');
        $rows = DB::table('concepts')
            ->selectRaw("vocabulary_id, count(*) as total, count(*) filter (where standard_concept = 'S') as standard")
            ->groupBy('vocabulary_id')->orderByDesc('total')->limit(25)->get();
        foreach ($rows as $r) {
            $this->line(sprintf('  %-24s %10s   (%s standard)', $r->vocabulary_id, number_format($r->total), number_format($r->standard)));
        }
        $this->newLine();

        // Search indexes present? (Postgres)
        if (DB::getDriverName() === 'pgsql') {
            $expected = [
                'concepts_name_unaccent_trgm', 'concepts_name_tsv',
                'concept_synonyms_name_unaccent_trgm', 'concept_synonyms_name_tsv',
            ];
            $present = DB::table('pg_indexes')->whereIn('indexname', $expected)->pluck('indexname')->all();
            $this->line('Search indexes:');
            foreach ($expected as $idx) {
                $ok = in_array($idx, $present, true);
                $this->line(sprintf('  %s %s', $ok ? '✔' : '✗', $idx));
            }
            $ancestors = DB::table('concept_ancestors')->count();
            $this->line('  hierarchy (concept_ancestors): '.($ancestors > 0 ? "loaded ($ancestors rows)" : 'not loaded — parents/children unavailable'));
            $this->newLine();
        }

        // Live sample search
        $term = $this->option('search');
        if ($term) {
            $this->line("Sample search for \"$term\":");
            $results = $search->search($term, [], 'all', true, true, 5);
            if ($results->isEmpty()) {
                $this->warn('  no results — check the term or that a matching vocabulary is loaded.');
            }
            foreach ($results as $r) {
                $this->line(sprintf('  %.3f  %-45s %-10s %s', $r->score, mb_substr($r->concept_name, 0, 45), $r->vocabulary_id, $r->matched_on));
            }
        } else {
            $this->line('Tip: add --search="a clinical term" to prove the ranking engine end-to-end.');
        }

        return self::SUCCESS;
    }
}
