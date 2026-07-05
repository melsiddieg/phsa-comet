<?php

namespace App\Services;

use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Named, vocab-tagged freezes of the current map set for reproducible ETL
 * consumption. Source codes are resolved the same way as the live STCM export
 * (linked term spot-1 code, or the unlinked map's carried snapshot).
 */
class ReleaseService
{
    public function create(string $name, ?string $notes, string $createdBy): Release
    {
        return DB::transaction(function () use ($name, $notes, $createdBy) {
            $vocab = DB::table('vocab_meta')->latest('id')->value('athena_release');

            $release = Release::create([
                'name' => $name,
                'vocab_release' => $vocab,
                'notes' => $notes,
                'created_by' => $createdBy,
                'map_count' => 0,
                'created_at' => now(),
            ]);

            $inserted = DB::table('maps as m')
                ->leftJoin('source_term_codes as sc', fn ($j) => $j->on('sc.source_term_id', '=', 'm.source_term_id')->where('sc.spot', 1))
                ->leftJoin('sheet_source_columns as ssc', function ($j) {
                    $j->on('ssc.sheet_id', '=', DB::raw('(select sheet_id from source_terms st where st.id = m.source_term_id)'))
                        ->where('ssc.spot', 1);
                })
                ->selectRaw('
                    ? as release_id,
                    m.source_term_id,
                    coalesce(sc.code, m.source_code) as source_code,
                    coalesce(ssc.vocabulary, m.source_vocabulary_id) as source_vocabulary_id,
                    coalesce(sc.description, m.source_code_description) as source_code_description,
                    m.target_concept_id, m.target_concept_name, m.target_vocabulary_id', [$release->id]);

            DB::table('release_maps')->insertUsing([
                'release_id', 'source_term_id', 'source_code', 'source_vocabulary_id',
                'source_code_description', 'target_concept_id', 'target_concept_name', 'target_vocabulary_id',
            ], $inserted);

            $count = DB::table('release_maps')->where('release_id', $release->id)->count();
            $release->update(['map_count' => $count]);

            return $release->refresh();
        });
    }
}
