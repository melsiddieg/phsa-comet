<?php

namespace App\Services;

use App\Models\Concept;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembles everything the concept-detail panel shows: the concept itself,
 * its synonyms (with language), direct relationships, immediate hierarchy
 * (parents/children via concept_ancestors, one level), and how often the team
 * already maps to it.
 */
class ConceptDetail
{
    public function __construct(private MapGuardrails $guardrails) {}

    /** @return array<string,mixed>|null */
    public function for(int $conceptId): ?array
    {
        $concept = Concept::where('concept_id', $conceptId)->first();
        if (! $concept) {
            return null;
        }

        return [
            'concept' => $concept,
            'synonyms' => DB::table('concept_synonyms')
                ->where('concept_id', $conceptId)
                ->get(['concept_synonym_name', 'language_concept_id']),
            'relationships' => DB::table('concept_relationships as r')
                ->join('concepts as c', 'c.concept_id', '=', 'r.concept_id_2')
                ->where('r.concept_id_1', $conceptId)
                ->orderBy('r.relationship_id')
                ->get(['r.relationship_id', 'c.concept_id', 'c.concept_name', 'c.concept_code', 'c.vocabulary_id', 'c.standard_concept']),
            'parents' => $this->hierarchy($conceptId, 'parents'),
            'children' => $this->hierarchy($conceptId, 'children'),
            'replacements' => $concept->isValidMapTarget() ? collect() : $this->guardrails->standardReplacements($conceptId),
            'team_uses' => DB::table('maps')->where('target_concept_id', $conceptId)->count(),
        ];
    }

    /** @return Collection<int, \stdClass> immediate parents or children (1 level) */
    private function hierarchy(int $conceptId, string $direction): Collection
    {
        // parents: rows where this concept is the descendant, 1 level up.
        // children: rows where this concept is the ancestor, 1 level down.
        [$selfCol, $otherCol] = $direction === 'parents'
            ? ['descendant_concept_id', 'ancestor_concept_id']
            : ['ancestor_concept_id', 'descendant_concept_id'];

        return DB::table('concept_ancestors as a')
            ->join('concepts as c', 'c.concept_id', '=', "a.$otherCol")
            ->where("a.$selfCol", $conceptId)
            ->where('a.min_levels_of_separation', 1)
            ->where('c.concept_id', '!=', $conceptId)
            ->orderBy('c.concept_name')
            ->limit(200)
            ->get(['c.concept_id', 'c.concept_name', 'c.concept_code', 'c.vocabulary_id', 'c.standard_concept', 'c.invalid_reason']);
    }
}
