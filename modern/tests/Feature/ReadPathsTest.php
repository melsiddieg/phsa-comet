<?php

namespace Tests\Feature;

use App\Livewire\MappingGrid;
use App\Models\Concept;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SheetSourceColumn;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReadPathsTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create(['enabled' => true, 'is_mapper' => true]);
    }

    private function seedSheet(): Sheet
    {
        $sheet = Sheet::create(['name' => 'Location']);
        SheetSourceColumn::create([
            'sheet_id' => $sheet->id, 'spot' => 1,
            'vocabulary' => 'Cerner Code', 'code_label' => 'Location Code', 'desc_label' => 'Location Description',
        ]);

        // mapped term
        $mapped = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 500, 'map_source' => 'User']);
        SourceTermCode::create(['source_term_id' => $mapped->id, 'spot' => 1, 'code' => 'A1', 'description' => 'Lions Gate Hospital']);
        Concept::create([
            'concept_id' => 8717, 'concept_name' => 'Inpatient Hospital', 'domain_id' => 'Visit',
            'vocabulary_id' => 'CMS Place of Service', 'concept_class_id' => 'Visit', 'standard_concept' => 'S',
            'concept_code' => '21', 'valid_start_date' => '19700101', 'valid_end_date' => '20991231',
        ]);
        MapEntry::create([
            'source_term_id' => $mapped->id, 'target_concept_id' => 8717,
            'target_concept_name' => 'Inpatient Hospital', 'target_vocabulary_id' => 'CMS Place of Service',
        ]);

        // unmapped term
        $unmapped = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 100]);
        SourceTermCode::create(['source_term_id' => $unmapped->id, 'spot' => 1, 'code' => 'B2', 'description' => 'Whistler Clinic']);

        return $sheet;
    }

    public function test_source_index_shows_live_progress(): void
    {
        $this->seedSheet();

        $this->actingAs($this->actingUser())
            ->get('/sheets')
            ->assertOk()
            ->assertSee('Source Terms by Cerner Area')
            ->assertSee('Location')
            ->assertSee('1 of 2 terms mapped');
    }

    public function test_mapping_grid_renders_and_filters(): void
    {
        $sheet = $this->seedSheet();
        $user = $this->actingUser();

        Livewire::actingAs($user)->test(MappingGrid::class, ['sheet' => $sheet])
            ->assertSee('Lions Gate Hospital')
            ->assertSee('Whistler Clinic')
            ->assertSee('Inpatient Hospital')
            // not-mapped filter hides the mapped term
            ->set('mapped', 'n')
            ->assertSee('Whistler Clinic')
            ->assertDontSee('Lions Gate Hospital')
            // description search
            ->set('mapped', 'all')
            ->set('q', 'lions')
            ->assertSee('Lions Gate Hospital')
            ->assertDontSee('Whistler Clinic');
    }

    public function test_domain_index_counts_maps(): void
    {
        $this->seedSheet();

        $this->actingAs($this->actingUser())
            ->get('/domains')
            ->assertOk()
            ->assertSee('Visit');
    }

    public function test_read_paths_require_auth(): void
    {
        $this->get('/sheets')->assertRedirect('/login');
        $this->get('/domains')->assertRedirect('/login');
    }
}
