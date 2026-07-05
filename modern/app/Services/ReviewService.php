<?php

namespace App\Services;

use App\Models\MapAudit;
use App\Models\MapSnapshot;
use App\Models\SourceTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Review workflow: a change is "pending" while any of a term's map_audits rows
 * has approved_by = NULL. Approving a term stamps those rows and snapshots the
 * term's current target set (map_snapshots), giving a before/after baseline.
 */
class ReviewService
{
    /** Terms with at least one pending change, most-recently-changed first. */
    public function pendingTerms(int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('map_audits as a')
            ->join('source_terms as t', 't.id', '=', 'a.source_term_id')
            ->join('sheets as s', 's.id', '=', 't.sheet_id')
            ->whereNull('a.approved_by')
            ->groupBy('t.id', 's.name')
            ->select('t.id', 's.name as sheet_name')
            ->selectRaw('count(*) as pending_count, max(a.created_at) as last_change')
            ->orderByDesc('last_change')
            ->paginate($perPage);
    }

    public function pendingCount(): int
    {
        return DB::table('map_audits')->whereNull('approved_by')->distinct()->count('source_term_id');
    }

    /** @return Collection<int, MapAudit> pending changes for a term, oldest first */
    public function pendingChanges(int $termId): Collection
    {
        return MapAudit::where('source_term_id', $termId)
            ->whereNull('approved_by')
            ->orderBy('created_at')
            ->get();
    }

    /** The target concept-id set as of the last approval (the "before" picture). */
    public function lastApprovedTargets(int $termId): Collection
    {
        $last = MapSnapshot::where('source_term_id', $termId)->max('created_at');
        if (! $last) {
            return collect();
        }

        return MapSnapshot::where('source_term_id', $termId)
            ->where('created_at', $last)
            ->pluck('concept_id');
    }

    /** Approve every pending change for a term and snapshot its current targets. */
    public function approve(int $termId, string $approver): int
    {
        return DB::transaction(function () use ($termId, $approver) {
            $affected = MapAudit::where('source_term_id', $termId)
                ->whereNull('approved_by')
                ->update(['approved_by' => $approver, 'approved_at' => now()]);

            $now = now();
            $targets = DB::table('maps')->where('source_term_id', $termId)->pluck('target_concept_id');
            foreach ($targets as $conceptId) {
                MapSnapshot::create([
                    'source_term_id' => $termId,
                    'concept_id' => $conceptId,
                    'approved_by' => $approver,
                    'created_at' => $now,
                ]);
            }

            return $affected;
        });
    }

    public function term(int $termId): SourceTerm
    {
        return SourceTerm::with(['codes', 'sheet', 'maps.targetConcept'])->findOrFail($termId);
    }
}
