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
    public function pendingTerms(int $perPage = 25, ?int $sheetId = null, ?int $sinceDays = null): LengthAwarePaginator
    {
        return DB::table('map_audits as a')
            ->join('source_terms as t', 't.id', '=', 'a.source_term_id')
            ->join('sheets as s', 's.id', '=', 't.sheet_id')
            ->whereNull('a.approved_by')
            ->when($sheetId, fn ($q) => $q->where('t.sheet_id', $sheetId))
            ->when($sinceDays, fn ($q) => $q->where('a.created_at', '>=', now()->subDays($sinceDays)))
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

    /**
     * Return a term to its mapper with a comment ("request changes"). Marks
     * the pending audits as reviewed (approved_by = the reviewer, with a
     * returned flag on the term) so the review queue clears, and surfaces the
     * term to the mapper via review_state='returned'.
     */
    public function requestChanges(int $termId, string $reviewer, string $comment): void
    {
        $term = SourceTerm::findOrFail($termId);

        DB::transaction(function () use ($term, $termId, $reviewer, $comment) {
            MapAudit::where('source_term_id', $termId)
                ->whereNull('approved_by')
                ->update(['approved_by' => $reviewer.' (returned)', 'approved_at' => now()]);

            $term->update([
                'review_state' => 'returned',
                'returned_to' => $term->updated_by,
            ]);

            \App\Models\SourceTermComment::updateOrCreate(
                ['source_term_id' => $termId],
                ['comment_text' => trim("[Reviewer $reviewer requested changes] ".$comment), 'updated_by' => $reviewer]
            );
        });
    }

    /** Bulk approve several terms at once. */
    public function approveMany(array $termIds, string $approver): int
    {
        $n = 0;
        foreach ($termIds as $id) {
            $n += $this->approve((int) $id, $approver) > 0 ? 1 : 0;
        }

        return $n;
    }
}
