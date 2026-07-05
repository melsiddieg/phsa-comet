<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Team throughput / workload figures for the reviewer-admin dashboard, all
 * from ground truth (map_audits, source_terms, maps).
 */
class TeamStats
{
    public function __construct(private ClaimService $claims) {}

    /** Per-mapper Add/Update counts over the last 7 and 30 days. */
    public function throughput(): Collection
    {
        return DB::table('map_audits')
            ->whereIn('action', ['Add', 'Update'])
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('username')
            ->selectRaw("count(*) filter (where created_at >= ?) as last7", [now()->subDays(7)])
            ->selectRaw('count(*) as last30')
            ->groupBy('username')
            ->orderByDesc('last30')
            ->get();
    }

    /** Review backlog aged into buckets. */
    public function reviewBacklog(): object
    {
        return DB::table('map_audits')
            ->whereNull('approved_by')
            ->selectRaw('count(distinct source_term_id) as total')
            ->selectRaw("count(distinct source_term_id) filter (where created_at < ?) as over7", [now()->subDays(7)])
            ->selectRaw("count(distinct source_term_id) filter (where created_at < ?) as over30", [now()->subDays(30)])
            ->first();
    }

    /** @return array<string,int> */
    public function pipeline(): array
    {
        return [
            'returned' => DB::table('source_terms')->where('review_state', 'returned')->count(),
            'sdo_send' => DB::table('source_terms')->where('exclude_status', 'SDO Submission - Send')->count(),
            'sdo_pending' => DB::table('source_terms')->where('exclude_status', 'SDO Submitted - Pending')->count(),
            'questions' => DB::table('source_terms')->where('exclude_status', 'Question - Pending')->count(),
            'claims_in_flight' => $this->claims->inFlightCount(),
        ];
    }
}
