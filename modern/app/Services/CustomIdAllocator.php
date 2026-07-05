<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Allocates stable 2-billion-range concept_ids for custom (Canadian) source
 * codes, backed by the custom_concept_ids registry so re-runs are idempotent.
 */
class CustomIdAllocator
{
    private const BASE = 2000000001;

    /** Stable concept_id for (vocabulary, code); allocates on first sight. */
    public function idFor(string $vocabularyId, string $conceptCode): int
    {
        $existing = DB::table('custom_concept_ids')
            ->where('vocabulary_id', $vocabularyId)
            ->where('concept_code', $conceptCode)
            ->value('concept_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $next = (int) (DB::table('custom_concept_ids')->max('concept_id') ?? self::BASE - 1) + 1;

        DB::table('custom_concept_ids')->insert([
            'concept_id' => $next,
            'vocabulary_id' => $vocabularyId,
            'concept_code' => $conceptCode,
            'created_at' => now(),
        ]);

        return $next;
    }

    /** Look up an already-allocated id (no allocation); null if unknown. */
    public function existingId(string $vocabularyId, string $conceptCode): ?int
    {
        $id = DB::table('custom_concept_ids')
            ->where('vocabulary_id', $vocabularyId)
            ->where('concept_code', $conceptCode)
            ->value('concept_id');

        return $id !== null ? (int) $id : null;
    }
}
