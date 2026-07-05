<?php

namespace Tests\Feature;

use App\Services\CustomIdAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CanadianConverterTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = storage_path('framework/testing/cihi_'.uniqid());
        File::ensureDirectoryExists($this->out);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    private function writeInput(string $name, string $content): string
    {
        $path = "{$this->out}/$name";
        File::put($path, $content);

        return $path;
    }

    public function test_convert_icd10ca_produces_custom_csvs_with_fr_synonyms(): void
    {
        $input = $this->writeInput('icd10ca.tsv', "I10\tEssential hypertension\tHypertension essentielle\nJ45\tAsthma\tAsthme\n");

        $this->artisan('comet:convert-cihi', ['type' => 'icd10ca', 'input' => $input, 'outdir' => $this->out])
            ->assertSuccessful();

        $concept = File::get("{$this->out}/CONCEPT_CUSTOM.csv");
        $this->assertStringContainsString("Essential hypertension", $concept);
        $this->assertStringContainsString("ICD10CA", $concept);
        // 2-billion id + non-standard source
        $this->assertMatchesRegularExpression('/^2000000\d+\tEssential hypertension\tCondition\tICD10CA/m', $concept);

        $syn = File::get("{$this->out}/CONCEPT_SYNONYM_CUSTOM.csv");
        $this->assertStringContainsString('Hypertension essentielle', $syn);

        $this->assertDatabaseHas('custom_concept_ids', ['vocabulary_id' => 'ICD10CA', 'concept_code' => 'I10']);
    }

    public function test_ids_are_stable_across_runs(): void
    {
        $input = $this->writeInput('icd10ca.tsv', "I10\tEssential hypertension\t\n");

        $this->artisan('comet:convert-cihi', ['type' => 'icd10ca', 'input' => $input, 'outdir' => $this->out])->run();
        $id1 = app(CustomIdAllocator::class)->existingId('ICD10CA', 'I10');

        $this->artisan('comet:convert-cihi', ['type' => 'icd10ca', 'input' => $input, 'outdir' => $this->out])->run();
        $id2 = app(CustomIdAllocator::class)->existingId('ICD10CA', 'I10');

        $this->assertNotNull($id1);
        $this->assertEquals($id1, $id2);
        $this->assertEquals(1, DB::table('custom_concept_ids')->where('concept_code', 'I10')->count());
    }

    public function test_refset_maps_canadian_code_to_standard_snomed(): void
    {
        // a loaded standard SNOMED concept (as from Athena)
        DB::table('concepts')->insert([
            'concept_id' => 320128, 'concept_name' => 'Essential hypertension', 'domain_id' => 'Condition',
            'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Clinical Finding', 'standard_concept' => 'S',
            'concept_code' => '59621000', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
        // allocate the Canadian code first
        $input = $this->writeInput('icd10ca.tsv', "I10\tEssential hypertension\t\n");
        $this->artisan('comet:convert-cihi', ['type' => 'icd10ca', 'input' => $input, 'outdir' => $this->out])->run();
        $canadianId = app(CustomIdAllocator::class)->existingId('ICD10CA', 'I10');

        // refset: SNOMED 59621000 -> ICD10CA I10, plus an unresolved pair
        $refset = $this->writeInput('refset.tsv', "59621000\tI10\n999999999\tI10\n");
        $this->artisan('comet:convert-cihi-maps', ['canadian_vocab' => 'ICD10CA', 'refset' => $refset, 'outdir' => $this->out])
            ->assertSuccessful();

        $rel = File::get("{$this->out}/CONCEPT_RELATIONSHIP_CUSTOM.csv");
        $this->assertStringContainsString("$canadianId\t320128\tMaps to", $rel);

        // the non-standard/unloaded SNOMED code went to the review file
        $review = File::get("{$this->out}/cihi_maps_review.tsv");
        $this->assertStringContainsString('999999999', $review);
    }

    public function test_invalid_type_rejected(): void
    {
        $input = $this->writeInput('x.tsv', "A\tB\n");
        $this->artisan('comet:convert-cihi', ['type' => 'nope', 'input' => $input, 'outdir' => $this->out])
            ->assertFailed();
    }
}
