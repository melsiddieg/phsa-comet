<?php

namespace Tests\Feature;

use App\Jobs\GenerateCandidates;
use App\Livewire\MappingMode;
use App\Models\Concept;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SheetVocabulary;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use App\Services\ConceptSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ThroughputTest extends TestCase
{
    use RefreshDatabase;

    private Sheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = Sheet::create(['name' => 'Event']);
        SheetVocabulary::create(['sheet_id' => $this->sheet->id, 'vocabulary' => 'SNOMED']);
        Concept::create(['concept_id' => 4152194, 'concept_name' => 'Systolic blood pressure', 'domain_id' => 'Measurement', 'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Observable Entity', 'standard_concept' => 'S', 'concept_code' => '271649006', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231']);
    }

    private function term(string $desc, int $count = 100): SourceTerm
    {
        $t = SourceTerm::create(['sheet_id' => $this->sheet->id, 'total_count' => $count]);
        SourceTermCode::create(['source_term_id' => $t->id, 'spot' => 1, 'code' => 'C'.$t->id, 'description' => $desc]);

        return $t;
    }

    public function test_auto_map_generates_ranked_candidates(): void
    {
        $t = $this->term('systolic blood pressure');

        (new GenerateCandidates($this->sheet->id))->handle(app(ConceptSearch::class));

        $this->assertDatabaseHas('map_candidates', [
            'source_term_id' => $t->id, 'concept_id' => 4152194, 'rank' => 1,
        ]);
    }

    public function test_auto_map_skips_already_mapped_terms(): void
    {
        $t = $this->term('systolic blood pressure');
        MapEntry::create(['source_term_id' => $t->id, 'target_concept_id' => 4152194, 'target_concept_name' => 'x', 'target_vocabulary_id' => 'SNOMED']);

        (new GenerateCandidates($this->sheet->id))->handle(app(ConceptSearch::class));

        $this->assertDatabaseMissing('map_candidates', ['source_term_id' => $t->id]);
    }

    public function test_mapping_mode_maps_via_keyboard_pick_with_equivalence(): void
    {
        $t = $this->term('systolic blood pressure', 900);
        DB::table('map_candidates')->insert([
            'source_term_id' => $t->id, 'concept_id' => 4152194, 'score' => 0.9, 'rank' => 1,
            'matched_on' => 'name', 'engine_version' => 'v1', 'generated_at' => now(),
        ]);
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        Livewire::actingAs($mapper)->test(MappingMode::class, ['sheet' => $this->sheet])
            ->assertSet('termId', $t->id)
            ->assertSee('Systolic blood pressure')     // candidate shown
            ->set('equivalence', 'EQUAL')
            ->call('pick', 1)
            ->assertSet('sessionMapped', 1);

        $this->assertDatabaseHas('maps', [
            'source_term_id' => $t->id, 'target_concept_id' => 4152194, 'equivalence' => 'EQUAL',
        ]);
        $this->assertDatabaseHas('map_audits', ['source_term_id' => $t->id, 'action' => 'Add']);
    }

    public function test_mapping_mode_advances_to_next_term(): void
    {
        $t1 = $this->term('systolic blood pressure', 900);
        $t2 = $this->term('another term', 100);
        DB::table('map_candidates')->insert([
            'source_term_id' => $t1->id, 'concept_id' => 4152194, 'score' => 0.9, 'rank' => 1,
            'matched_on' => 'name', 'engine_version' => 'v1', 'generated_at' => now(),
        ]);
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        // highest usage first (t1), then after mapping, advances to t2
        Livewire::actingAs($mapper)->test(MappingMode::class, ['sheet' => $this->sheet])
            ->assertSet('termId', $t1->id)
            ->call('pick', 1)
            ->assertSet('termId', $t2->id);
    }

    public function test_mapping_mode_status_key_excludes_and_advances(): void
    {
        $t = $this->term('weird term', 500);
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        Livewire::actingAs($mapper)->test(MappingMode::class, ['sheet' => $this->sheet])
            ->assertSet('termId', $t->id)
            ->call('setStatus', 'sdo');

        $this->assertDatabaseHas('source_terms', ['id' => $t->id, 'exclude_status' => 'SDO Submission - Send']);
        $this->assertDatabaseHas('map_audits', ['source_term_id' => $t->id, 'action' => 'Set to SDO Submission']);
    }

    public function test_mapping_mode_requires_mapper(): void
    {
        $viewer = User::factory()->create(['enabled' => true, 'is_mapper' => false]);
        Livewire::actingAs($viewer)->test(MappingMode::class, ['sheet' => $this->sheet])->assertForbidden();
    }
}
