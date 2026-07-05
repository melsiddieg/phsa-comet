<?php

namespace Tests\Feature;

use App\Livewire\ImportManager;
use App\Models\ImportRun;
use App\Models\Sheet;
use App\Models\SheetAttribute;
use App\Models\SheetSourceColumn;
use App\Models\SourceTerm;
use App\Models\User;
use App\Services\MappingReportImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private Sheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheet = Sheet::create(['name' => 'Location']);
        SheetSourceColumn::create([
            'sheet_id' => $this->sheet->id, 'spot' => 1, 'vocabulary' => 'Cerner Code',
            'code_label' => 'Location Code', 'desc_label' => 'Location Description',
        ]);
        SheetAttribute::create(['sheet_id' => $this->sheet->id, 'name' => 'Location Type', 'col_position' => 3]);
    }

    private function writeFile(array $rows): string
    {
        $header = "Location Code\tLocation Description\tLocation Type\tOMOP Concept ID\tCount\n";
        $path = tempnam(sys_get_temp_dir(), 'mr').'.txt';
        file_put_contents($path, $header.collect($rows)->map(fn ($r) => implode("\t", $r))->implode("\n")."\n");

        return $path;
    }

    public function test_import_inserts_new_terms_with_codes_attributes_and_suggested_target(): void
    {
        $path = $this->writeFile([
            ['A1', 'Lions Gate Hospital', 'Facility', '8717', '510,371'],
            ['A2', 'Whistler Clinic', 'Clinic', '', '1,200'],
        ]);

        $run = app(MappingReportImporter::class)->import($this->sheet, $path, 'tester');

        $this->assertEquals('completed', $run->status);
        $this->assertEquals(2, $run->rows_total);
        $this->assertEquals(2, $run->rows_new);

        $t = SourceTerm::where('sheet_id', $this->sheet->id)->get();
        $this->assertCount(2, $t);
        $lions = $t->firstWhere('total_count', 510371);
        $this->assertEquals('New in MR', $lions->mr_status);
        $this->assertEquals('User', $lions->map_source); // numeric OMOP id, no Reviewed col
        $this->assertDatabaseHas('source_term_codes', ['source_term_id' => $lions->id, 'code' => 'A1', 'description' => 'Lions Gate Hospital']);
        $this->assertDatabaseHas('source_term_attributes', ['source_term_id' => $lions->id, 'value' => 'Facility']);
        $this->assertDatabaseHas('suggested_targets', ['source_term_id' => $lions->id, 'concept_id' => 8717]);

        unlink($path);
    }

    public function test_reimport_updates_existing_and_marks_absent(): void
    {
        // first import: two terms
        $path1 = $this->writeFile([
            ['A1', 'Lions Gate Hospital', 'Facility', '8717', '500'],
            ['A2', 'Whistler Clinic', 'Clinic', '', '100'],
        ]);
        app(MappingReportImporter::class)->import($this->sheet, $path1, 'tester');
        unlink($path1);

        // second import: A1 updated (new description), A2 gone, A3 new
        $path2 = $this->writeFile([
            ['A1', 'Lions Gate Hospital (updated)', 'Facility', '8717', '600'],
            ['A3', 'Squamish Clinic', 'Clinic', '', '50'],
        ]);
        $run = app(MappingReportImporter::class)->import($this->sheet, $path2, 'tester');
        unlink($path2);

        $this->assertEquals(1, $run->rows_new);      // A3
        $this->assertEquals(1, $run->rows_updated);  // A1
        $this->assertEquals(1, $run->rows_absent);   // A2

        $a1 = SourceTerm::whereHas('codes', fn ($q) => $q->where('code', 'A1'))->first();
        $this->assertEquals('Still in MR', $a1->mr_status);
        $this->assertEquals(600, $a1->total_count);
        $this->assertDatabaseHas('source_term_codes', ['source_term_id' => $a1->id, 'description' => 'Lions Gate Hospital (updated)']);

        $a2 = SourceTerm::whereHas('codes', fn ($q) => $q->where('code', 'A2'))->first();
        $this->assertEquals('Absent in latest MR', $a2->mr_status);
    }

    public function test_reimport_relinks_orphaned_map(): void
    {
        // a map with no source term, carrying a spot-1 code snapshot
        DB::table('maps')->insert([
            'source_term_id' => null, 'source_code' => 'A1', 'source_vocabulary_id' => 'Cerner Code',
            'source_code_description' => 'Lions Gate Hospital', 'target_concept_id' => 8717,
            'target_concept_name' => 'Inpatient Hospital', 'target_vocabulary_id' => 'CMS Place of Service',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $path = $this->writeFile([['A1', 'Lions Gate Hospital', 'Facility', '8717', '500']]);
        app(MappingReportImporter::class)->import($this->sheet, $path, 'tester');
        unlink($path);

        $term = SourceTerm::whereHas('codes', fn ($q) => $q->where('code', 'A1'))->first();
        $this->assertDatabaseHas('maps', ['source_term_id' => $term->id, 'source_code' => null]);
        $this->assertEquals(0, DB::table('maps')->whereNull('source_term_id')->count());
    }

    public function test_header_validation_fails_on_missing_column(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mr').'.txt';
        file_put_contents($path, "Wrong Column\tOMOP Concept ID\nx\t1\n");

        $threw = false;
        try {
            app(MappingReportImporter::class)->import($this->sheet, $path, 'tester');
        } catch (\RuntimeException $e) {
            $threw = true;
            // Either the misplaced attribute or the missing source column is reported.
            $this->assertMatchesRegularExpression('/Location Type|Location Code/', $e->getMessage());
        }
        unlink($path);

        $this->assertTrue($threw, 'expected the importer to reject an invalid header');
        $this->assertEquals('failed', ImportRun::latest('id')->first()->status);
    }

    public function test_import_ui_dispatches_job_for_importer(): void
    {
        Queue::fake();
        config(['comet.mr_data_path' => sys_get_temp_dir()]);
        $path = sys_get_temp_dir().'/Location.txt';
        file_put_contents($path, "Location Code\tLocation Description\tLocation Type\tOMOP Concept ID\tCount\nA1\tx\tFacility\t8717\t5\n");

        $importer = User::factory()->create(['enabled' => true, 'is_importer' => true]);

        Livewire::actingAs($importer)->test(ImportManager::class)
            ->call('startImport', $this->sheet->id)
            ->assertSee('Import queued');

        Queue::assertPushed(\App\Jobs\ImportMappingReport::class);
        unlink($path);
    }

    public function test_import_ui_forbidden_for_non_importer(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true, 'is_importer' => false]);

        Livewire::actingAs($mapper)->test(ImportManager::class)->assertForbidden();
    }
}
