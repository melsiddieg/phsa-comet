<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live per-sheet mapping progress, computed from ground truth rather than the
 * legacy denormalized counters (which drifted). A term counts as "mapped" when
 * it is not excluded and has at least one row in `maps`.
 */
class SheetProgress
{
    /** @return Collection<int, object> one row per sheet with progress counts */
    public function all(): Collection
    {
        return collect(DB::select(<<<'SQL'
            select s.id, s.name,
                count(t.id)                                                              as num_items,
                count(t.id) filter (where t.exclude_status = 'Out of Scope - Exclude')   as excluded,
                count(t.id) filter (where t.exclude_status = 'Question - Pending')        as question,
                count(t.id) filter (where t.exclude_status in ('SDO Submission - Send','SDO Submitted - Pending')) as sdo,
                count(t.id) filter (where coalesce(t.exclude_status,'') = '' and mp.source_term_id is not null) as mapped,
                count(t.id) filter (where coalesce(t.exclude_status,'') = '' and mp.source_term_id is null)     as unmapped,
                coalesce(sum(t.total_count), 0)                                           as total_vol,
                coalesce(sum(t.total_count) filter (where coalesce(t.exclude_status,'') = '' and mp.source_term_id is not null), 0) as mapped_vol
            from sheets s
            join source_terms t on t.sheet_id = s.id
            left join (select distinct source_term_id from maps where source_term_id is not null) mp
                on mp.source_term_id = t.id
            group by s.id, s.name
            order by s.name
        SQL));
    }

    /** Overall totals across all sheets. */
    public function totals(): object
    {
        return DB::selectOne(<<<'SQL'
            select
                count(*)                                                                  as num_items,
                count(*) filter (where coalesce(t.exclude_status,'') = '' and mp.source_term_id is not null) as mapped,
                count(*) filter (where t.exclude_status = 'Out of Scope - Exclude')        as excluded
            from source_terms t
            left join (select distinct source_term_id from maps where source_term_id is not null) mp
                on mp.source_term_id = t.id
        SQL);
    }
}
