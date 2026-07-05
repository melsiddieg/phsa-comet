<?php

namespace App\Services;

use App\Models\MapAudit;
use App\Models\MapEntry;
use App\Models\SourceTerm;

/**
 * Append-only audit trail. Every map change and term-status change writes one
 * map_audits row carrying a JSON snapshot of the term's source codes at that
 * moment. approved_by stays NULL = pending review (the M5 queue).
 */
class MapAuditor
{
    public function logMapChange(string $action, MapEntry $map, string $username, ?int $beforeTargetConceptId = null): MapAudit
    {
        $term = $map->sourceTerm;

        return MapAudit::create([
            'map_id' => $map->id,
            'source_term_id' => $map->source_term_id,
            'action' => $action,
            'target_concept_id' => $map->target_concept_id,
            'target_concept_name' => $map->target_concept_name,
            'target_vocabulary_id' => $map->target_vocabulary_id,
            'before_target_concept_id' => $beforeTargetConceptId,
            'source_snapshot' => $term ? $this->snapshot($term) : null,
            'username' => $username,
            'created_at' => now(),
        ]);
    }

    public function logStatusChange(string $action, SourceTerm $term, string $username): MapAudit
    {
        return MapAudit::create([
            'map_id' => null,
            'source_term_id' => $term->id,
            'action' => $action,
            'source_snapshot' => $this->snapshot($term),
            'username' => $username,
            'created_at' => now(),
        ]);
    }

    private function snapshot(SourceTerm $term): array
    {
        return $term->codes->map(fn ($c) => [
            'spot' => $c->spot,
            'code' => $c->code,
            'description' => $c->description,
        ])->all();
    }
}
