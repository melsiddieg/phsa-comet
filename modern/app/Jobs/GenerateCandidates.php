<?php

namespace App\Jobs;

use App\Services\ConceptSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates map_candidates for a sheet's unmapped, unexcluded terms by
 * running the ConceptSearch engine on each term's spot-1 description. Wipes
 * and rewrites per sheet so it's safe to re-run after a vocabulary refresh.
 * Never writes a map.
 */
class GenerateCandidates implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    private const TOP_N = 8;
    private const ENGINE = 'v1';

    public function __construct(public int $sheetId) {}

    public function handle(ConceptSearch $search): void
    {
        // Allowed vocabularies for this sheet constrain candidates.
        $vocabularies = DB::table('sheet_vocabularies')->where('sheet_id', $this->sheetId)->pluck('vocabulary')->all();

        // Unmapped, unexcluded terms with a spot-1 description.
        $terms = DB::table('source_terms as t')
            ->join('source_term_codes as c', fn ($j) => $j->on('c.source_term_id', '=', 't.id')->where('c.spot', 1))
            ->where('t.sheet_id', $this->sheetId)
            ->whereNull('t.exclude_status')
            ->whereNotExists(fn ($q) => $q->from('maps')->whereColumn('maps.source_term_id', 't.id'))
            ->whereRaw("coalesce(c.description, '') <> ''")
            ->select('t.id', 'c.description')
            ->orderByDesc('t.total_count');

        $termIds = $terms->pluck('id')->all();
        DB::table('map_candidates')->whereIn('source_term_id', $termIds)->delete();

        $now = now();
        foreach ($terms->cursor() as $term) {
            $results = $search->search($term->description, $vocabularies, 'all', true, true, self::TOP_N);
            if ($results->isEmpty()) {
                continue;
            }

            $rows = [];
            $rank = 1;
            foreach ($results as $r) {
                $rows[] = [
                    'source_term_id' => $term->id,
                    'concept_id' => $r->concept_id,
                    'score' => $r->score,
                    'rank' => $rank++,
                    'matched_on' => $r->matched_on,
                    'engine_version' => self::ENGINE,
                    'generated_at' => $now,
                ];
            }
            DB::table('map_candidates')->insert($rows);
        }
    }
}
