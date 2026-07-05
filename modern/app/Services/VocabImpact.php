<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * After a vocabulary refresh, finds maps whose target is no longer a valid
 * CDM v5.4 target — deprecated (invalid_reason set), no longer standard, or
 * missing from the loaded vocabulary — and resolves suggested replacements.
 */
class VocabImpact
{
    public function __construct(private MapGuardrails $guardrails) {}

    /**
     * Stale maps with a suggested replacement.
     *
     * @return Collection<int, \stdClass>
     */
    public function staleMaps(int $limit = 500): Collection
    {
        $rows = DB::table('maps as m')
            ->leftJoin('concepts as c', 'c.concept_id', '=', 'm.target_concept_id')
            ->leftJoin('source_term_codes as sc', fn ($j) => $j->on('sc.source_term_id', '=', 'm.source_term_id')->where('sc.spot', 1))
            ->where(fn ($q) => $q->whereNull('c.concept_id')
                ->orWhereNotNull('c.invalid_reason')
                ->orWhereNull('c.standard_concept')
                ->orWhere('c.standard_concept', '!=', 'S'))
            ->orderBy('m.id')
            ->limit($limit)
            ->get([
                'm.id as map_id', 'm.source_term_id', 'm.target_concept_id', 'm.target_concept_name', 'm.target_vocabulary_id',
                'c.concept_id as current_concept_id', 'c.invalid_reason', 'c.standard_concept',
                DB::raw('coalesce(sc.code, m.source_code) as source_code'),
                DB::raw('coalesce(sc.description, m.source_code_description) as source_description'),
            ]);

        return $rows->map(function ($row) {
            $row->problem = $row->current_concept_id === null
                ? 'missing from vocabulary'
                : ($row->invalid_reason !== null ? "deprecated (invalid_reason = {$row->invalid_reason})" : 'no longer standard');
            $row->replacements = $row->current_concept_id === null
                ? collect()
                : $this->guardrails->standardReplacements((int) $row->target_concept_id);

            return $row;
        });
    }

    public function staleCount(): int
    {
        return DB::table('maps as m')
            ->leftJoin('concepts as c', 'c.concept_id', '=', 'm.target_concept_id')
            ->where(fn ($q) => $q->whereNull('c.concept_id')
                ->orWhereNotNull('c.invalid_reason')
                ->orWhereNull('c.standard_concept')
                ->orWhere('c.standard_concept', '!=', 'S'))
            ->count();
    }
}
