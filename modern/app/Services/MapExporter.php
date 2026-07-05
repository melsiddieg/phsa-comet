<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Map exports. STCM is the OMOP SOURCE_TO_CONCEPT_MAP shape (one row per map);
 * it covers both linked maps (source code from the term's spot-1) and unlinked
 * maps (source snapshot carried on the map). Streamed to avoid buffering large
 * result sets.
 */
class MapExporter
{
    public function streamStcm(?int $releaseId = null): StreamedResponse
    {
        $filename = $releaseId
            ? "SourceToConceptMap_release_{$releaseId}.csv"
            : 'SourceToConceptMap_'.now()->format('Ymd').'.csv';

        return $this->stream($filename, function ($out) use ($releaseId) {
            fputcsv($out, [
                'source_code', 'source_concept_id', 'source_vocabulary_id', 'source_code_description',
                'target_concept_id', 'target_vocabulary_id', 'valid_start_date', 'valid_end_date', 'invalid_reason',
            ]);

            if ($releaseId) {
                $this->releaseRows($releaseId)->each(fn ($r) => fputcsv($out, [
                    $r->source_code, 0, $r->source_vocabulary_id, $r->source_code_description,
                    $r->target_concept_id, $r->target_vocabulary_id,
                    $r->valid_start_date ?? '19700101', $r->valid_end_date ?? '20991231', $r->invalid_reason,
                ]));

                return;
            }

            $this->liveRows()->each(fn ($r) => fputcsv($out, [
                $r->source_code, 0, $r->source_vocabulary_id, $r->source_code_description,
                $r->target_concept_id, $r->target_vocabulary_id,
                $r->valid_start_date ?? '19700101', $r->valid_end_date ?? '20991231', $r->invalid_reason,
            ]));
        });
    }

    public function streamExclusions(): StreamedResponse
    {
        return $this->stream('Exclusions_'.now()->format('Ymd').'.csv', function ($out) {
            fputcsv($out, ['sheet', 'source_code', 'source_description']);
            DB::table('source_terms as t')
                ->join('sheets as s', 's.id', '=', 't.sheet_id')
                ->leftJoin('source_term_codes as c', fn ($j) => $j->on('c.source_term_id', '=', 't.id')->where('c.spot', 1))
                ->where('t.exclude_status', 'Out of Scope - Exclude')
                ->orderBy('s.name')
                ->select('s.name as sheet', 'c.code', 'c.description')
                ->lazy()->each(fn ($r) => fputcsv($out, [$r->sheet, $r->code, $r->description]));
        });
    }

    /** Live maps as STCM rows (linked term codes + unlinked snapshots). */
    private function liveRows()
    {
        return DB::table('maps as m')
            ->leftJoin('source_term_codes as sc', fn ($j) => $j->on('sc.source_term_id', '=', 'm.source_term_id')->where('sc.spot', 1))
            ->leftJoin('sheet_source_columns as ssc', function ($j) {
                $j->on('ssc.sheet_id', '=', DB::raw('(select sheet_id from source_terms st where st.id = m.source_term_id)'))
                    ->where('ssc.spot', 1);
            })
            ->leftJoin('concepts as c', 'c.concept_id', '=', 'm.target_concept_id')
            ->orderBy('m.id')
            ->selectRaw("
                coalesce(sc.code, m.source_code) as source_code,
                coalesce(ssc.vocabulary, m.source_vocabulary_id) as source_vocabulary_id,
                coalesce(sc.description, m.source_code_description) as source_code_description,
                m.target_concept_id, m.target_vocabulary_id,
                c.valid_start_date, c.valid_end_date, c.invalid_reason")
            ->lazy();
    }

    private function releaseRows(int $releaseId)
    {
        return DB::table('release_maps as r')
            ->leftJoin('concepts as c', 'c.concept_id', '=', 'r.target_concept_id')
            ->where('r.release_id', $releaseId)
            ->orderBy('r.id')
            ->selectRaw('r.source_code, r.source_vocabulary_id, r.source_code_description,
                         r.target_concept_id, r.target_vocabulary_id,
                         c.valid_start_date, c.valid_end_date, c.invalid_reason')
            ->lazy();
    }

    private function stream(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            $writer($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
