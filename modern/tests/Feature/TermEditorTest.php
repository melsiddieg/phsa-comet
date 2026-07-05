<?php

namespace Tests\Feature;

use App\Livewire\TermEditor;
use App\Models\Concept;
use App\Models\ConceptRelationship;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SheetSourceColumn;
use App\Models\SheetVocabulary;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TermEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $mapper;

    private Sheet $sheet;

    private SourceTerm $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        $this->sheet = Sheet::create(['name' => 'Event']);
        SheetSourceColumn::create(['sheet_id' => $this->sheet->id, 'spot' => 1, 'vocabulary' => 'Cerner Code', 'code_label' => 'Cerner Code', 'desc_label' => 'Cerner Name']);
        SheetVocabulary::create(['sheet_id' => $this->sheet->id, 'vocabulary' => 'SNOMED']);

        $this->term = SourceTerm::create(['sheet_id' => $this->sheet->id, 'total_count' => 42]);
        SourceTermCode::create(['source_term_id' => $this->term->id, 'spot' => 1, 'code' => 'E1', 'description' => 'Systolic BP measurement']);

        // Standard valid target
        Concept::create([
            'concept_id' => 4152194, 'concept_name' => 'Systolic blood pressure', 'domain_id' => 'Measurement',
            'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Observable Entity', 'standard_concept' => 'S',
            'concept_code' => '271649006', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
        // Non-standard concept with a Maps to replacement
        Concept::create([
            'concept_id' => 44806682, 'concept_name' => 'Blood pressure reading', 'domain_id' => 'Measurement',
            'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Observable Entity', 'standard_concept' => null,
            'concept_code' => '392570002', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
        ConceptRelationship::create([
            'concept_id_1' => 44806682, 'concept_id_2' => 4152194, 'relationship_id' => 'Maps to',
            'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
    }

    public function test_add_map_happy_path_writes_map_and_audit(): void
    {
        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '271649006')
            ->set('newVocabulary', 'SNOMED')
            ->call('addMap')
            ->assertSet('message', 'Map added.')
            ->assertDispatched('map-saved');

        $this->assertDatabaseHas('maps', [
            'source_term_id' => $this->term->id,
            'target_concept_id' => 4152194,
        ]);
        $this->assertDatabaseHas('map_audits', [
            'source_term_id' => $this->term->id,
            'action' => 'Add',
            'target_concept_id' => 4152194,
            'username' => $this->mapper->name,
            'approved_by' => null, // pending review
        ]);
    }

    public function test_non_standard_target_is_blocked_with_replacement_offered(): void
    {
        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '392570002')
            ->set('newVocabulary', 'SNOMED')
            ->call('addMap')
            ->assertSet('messageType', 'error');

        $this->assertDatabaseMissing('maps', ['source_term_id' => $this->term->id]);

        // replacement suggested via 'Maps to'
        $component = Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '392570002')
            ->set('newVocabulary', 'SNOMED')
            ->call('addMap');
        $replacements = $component->get('replacements');
        $this->assertCount(1, $replacements);
        $this->assertEquals('271649006', $replacements[0]['concept_code']);
    }

    public function test_unknown_code_rejected(): void
    {
        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '99999999')
            ->set('newVocabulary', 'SNOMED')
            ->call('addMap')
            ->assertSet('messageType', 'error');

        $this->assertDatabaseMissing('maps', ['source_term_id' => $this->term->id]);
    }

    public function test_delete_map_audited(): void
    {
        $map = MapEntry::create([
            'source_term_id' => $this->term->id, 'target_concept_id' => 4152194,
            'target_concept_name' => 'Systolic blood pressure', 'target_vocabulary_id' => 'SNOMED',
        ]);

        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->call('deleteMap', $map->id);

        $this->assertDatabaseMissing('maps', ['id' => $map->id]);
        $this->assertDatabaseHas('map_audits', ['map_id' => $map->id, 'action' => 'Delete']);
    }

    public function test_propagation_maps_identical_unmapped_twins(): void
    {
        // two twins, same description (one case-different), one already-different term
        foreach (['Systolic BP measurement', 'SYSTOLIC bp MEASUREMENT', 'Something else'] as $i => $desc) {
            $t = SourceTerm::create(['sheet_id' => $this->sheet->id]);
            SourceTermCode::create(['source_term_id' => $t->id, 'spot' => 1, 'code' => "T$i", 'description' => $desc]);
        }

        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->call('propagate', 4152194);

        // the editor's own term + 2 twins mapped; the unrelated term untouched
        $this->assertEquals(3, MapEntry::where('target_concept_id', 4152194)->count());
        $this->assertEquals(3, \App\Models\MapAudit::where('action', 'Add')->count());
    }

    public function test_status_change_audited_and_saved(): void
    {
        Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('excludeStatus', 'Question - Pending')
            ->set('commentText', 'Need SME input')
            ->call('saveStatus');

        $this->assertDatabaseHas('source_terms', ['id' => $this->term->id, 'exclude_status' => 'Question - Pending']);
        $this->assertDatabaseHas('source_term_comments', ['source_term_id' => $this->term->id, 'comment_text' => 'Need SME input']);
        $this->assertDatabaseHas('map_audits', ['source_term_id' => $this->term->id, 'action' => 'Question']);
    }

    public function test_non_mapper_cannot_write(): void
    {
        $viewer = User::factory()->create(['enabled' => true, 'is_mapper' => false]);

        Livewire::actingAs($viewer)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '271649006')
            ->set('newVocabulary', 'SNOMED')
            ->call('addMap')
            ->assertForbidden();

        $this->assertDatabaseMissing('maps', ['source_term_id' => $this->term->id]);
    }

    public function test_concept_search_finds_by_name_with_filters(): void
    {
        $component = Livewire::actingAs($this->mapper)
            ->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('searchQuery', 'blood pressure')
            ->call('runSearch')
            ->assertSee('Systolic blood pressure');

        // standard-only on by default: non-standard concept hidden
        $component->assertDontSee('Blood pressure reading');

        // untick standard-only: it appears
        $component->set('searchStandardOnly', false)
            ->assertSee('Blood pressure reading');
    }
}
