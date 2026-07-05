<?php

namespace App\Http\Controllers;

use App\Services\SheetProgress;
use App\Services\TeamStats;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SheetController extends Controller
{
    /** Source Terms by Cerner Area — live progress dashboard. */
    public function sourceIndex(SheetProgress $progress): View
    {
        return view('sheets.source-index', [
            'rows' => $progress->all(),
            'totals' => $progress->totals(),
            'vocabRelease' => DB::table('vocab_meta')->latest('id')->value('athena_release'),
        ]);
    }

    /** Team dashboard — throughput, backlog, pipeline (reviewer/admin). */
    public function team(TeamStats $stats): View
    {
        abort_unless(auth()->user()->is_reviewer || auth()->user()->is_portal_admin, 403);

        return view('team.dashboard', [
            'throughput' => $stats->throughput(),
            'backlog' => $stats->reviewBacklog(),
            'pipeline' => $stats->pipeline(),
        ]);
    }

    /** Mapped Terms by OMOP Domain — domains with mapped-term counts. */
    public function domainIndex(): View
    {
        $domains = DB::table('maps')
            ->join('concepts', 'concepts.concept_id', '=', 'maps.target_concept_id')
            ->selectRaw('concepts.domain_id, count(distinct maps.id) as map_count')
            ->groupBy('concepts.domain_id')
            ->orderByDesc('map_count')
            ->get();

        $totalMaps = DB::table('maps')->count();
        // A map's domain lives in the concepts table; targets absent from the
        // loaded vocabulary can't be attributed to a domain (partial/empty
        // vocabulary), so surface that instead of silently dropping them.
        $unresolved = DB::table('maps')
            ->leftJoin('concepts', 'concepts.concept_id', '=', 'maps.target_concept_id')
            ->whereNull('concepts.concept_id')
            ->count();

        return view('sheets.domain-index', [
            'domains' => $domains,
            'totalMaps' => $totalMaps,
            'resolved' => $totalMaps - $unresolved,
            'unresolved' => $unresolved,
            'vocabRelease' => DB::table('vocab_meta')->latest('id')->value('athena_release'),
        ]);
    }
}
