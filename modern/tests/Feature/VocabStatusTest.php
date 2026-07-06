<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VocabStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_no_vocab_when_empty(): void
    {
        $this->artisan('comet:vocab-status')
            ->expectsOutputToContain('No vocabulary loaded yet')
            ->assertSuccessful();
    }

    public function test_reports_release_counts_and_sample_search(): void
    {
        DB::table('vocab_meta')->insert([
            'athena_release' => 'v5.0 TEST', 'loaded_by' => 'tester', 'concept_count' => 1, 'loaded_at' => now(),
        ]);
        DB::table('concepts')->insert([
            'concept_id' => 320128, 'concept_name' => 'Essential hypertension', 'domain_id' => 'Condition',
            'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Clinical Finding', 'standard_concept' => 'S',
            'concept_code' => '59621000', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);

        $this->artisan('comet:vocab-status', ['--search' => 'hypertension'])
            ->expectsOutputToContain('v5.0 TEST')          // release
            ->expectsOutputToContain('SNOMED')             // per-vocabulary breakdown
            ->expectsOutputToContain('Sample search for')  // search section ran
            ->assertSuccessful();
    }
}
