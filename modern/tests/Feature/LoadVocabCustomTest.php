<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The staged COPY loader is Postgres-specific; on the SQLite test DB we can't
 * exercise it directly. Instead we assert the custom-extension contract at the
 * data layer: a 2-billion source concept with a "Maps to" relationship to a
 * standard concept is resolvable by the guardrails/impact services the app
 * relies on — the same shape comet:load-vocab produces from *_CUSTOM.csv.
 */
class LoadVocabCustomTest extends TestCase
{
    use RefreshDatabase;

    public function test_canadian_source_concept_maps_to_standard(): void
    {
        // standard target (as from Athena)
        DB::table('concepts')->insert([
            'concept_id' => 320128, 'concept_name' => 'Essential hypertension', 'domain_id' => 'Condition',
            'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Clinical Finding', 'standard_concept' => 'S',
            'concept_code' => '59621000', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
        // custom ICD-10-CA source concept (2-billion range, non-standard)
        DB::table('concepts')->insert([
            'concept_id' => 2000000001, 'concept_name' => 'Essential (primary) hypertension', 'domain_id' => 'Condition',
            'vocabulary_id' => 'ICD10CA', 'concept_class_id' => 'ICD10CA code', 'standard_concept' => null,
            'concept_code' => 'I10', 'valid_start_date' => '20220401', 'valid_end_date' => '20991231',
        ]);
        DB::table('concept_relationships')->insert([
            'concept_id_1' => 2000000001, 'concept_id_2' => 320128, 'relationship_id' => 'Maps to',
            'valid_start_date' => '20220401', 'valid_end_date' => '20991231',
        ]);

        // The guardrails replacement query resolves the Canadian code to its standard concept.
        $replacements = app(\App\Services\MapGuardrails::class)->standardReplacements(2000000001);

        $this->assertCount(1, $replacements);
        $this->assertEquals(320128, $replacements->first()->concept_id);
        $this->assertEquals('Essential hypertension', $replacements->first()->concept_name);

        // Custom concept uses the reserved 2-billion range.
        $this->assertGreaterThanOrEqual(2000000000, DB::table('concepts')->where('vocabulary_id', 'ICD10CA')->value('concept_id'));
    }
}
