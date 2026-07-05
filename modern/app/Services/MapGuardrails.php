<?php

namespace App\Services;

use App\Models\Concept;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CDM v5.4 target rules: maps must point at standard (standard_concept='S'),
 * valid (invalid_reason IS NULL) concepts. When a chosen target fails, the
 * vocabulary's own replacement relationships supply the alternatives.
 */
class MapGuardrails
{
    public const REPLACEMENT_RELATIONSHIPS = [
        'Maps to', 'Concept replaced by', 'Concept poss_eq to', 'Concept same_as to',
    ];

    public function resolve(string $conceptCode, string $vocabularyId): ?Concept
    {
        return Concept::where('concept_code', $conceptCode)
            ->where('vocabulary_id', $vocabularyId)
            ->first();
    }

    public function isValidTarget(?Concept $concept): bool
    {
        return $concept !== null && $concept->isValidMapTarget();
    }

    /** Standard, valid replacement candidates for a rejected/stale concept. */
    public function standardReplacements(int $conceptId): Collection
    {
        return DB::table('concept_relationships as r')
            ->join('concepts as c', 'c.concept_id', '=', 'r.concept_id_2')
            ->where('r.concept_id_1', $conceptId)
            ->whereIn('r.relationship_id', self::REPLACEMENT_RELATIONSHIPS)
            ->where('c.concept_id', '!=', $conceptId)
            ->where('c.standard_concept', 'S')
            ->whereNull('c.invalid_reason')
            ->distinct()
            ->get(['c.concept_id', 'c.concept_name', 'c.concept_code', 'c.domain_id', 'c.vocabulary_id']);
    }
}
