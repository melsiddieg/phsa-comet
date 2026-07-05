<?php

namespace App\Http\Controllers;

use App\Services\SheetProgress;
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

    /** Mapped Terms by OMOP Domain — domains with mapped-term counts. */
    public function domainIndex(): View
    {
        $domains = DB::table('maps')
            ->join('concepts', 'concepts.concept_id', '=', 'maps.target_concept_id')
            ->selectRaw('concepts.domain_id, count(distinct maps.id) as map_count')
            ->groupBy('concepts.domain_id')
            ->orderByDesc('map_count')
            ->get();

        return view('sheets.domain-index', ['domains' => $domains]);
    }
}
