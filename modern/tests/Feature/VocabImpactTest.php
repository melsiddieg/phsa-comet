<?php

namespace Tests\Feature;

use App\Livewire\VocabImpactReport;
use App\Models\Concept;
use App\Models\ConceptRelationship;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VocabImpactTest extends TestCase
{
    use RefreshDatabase;

    private SourceTerm $term;

    private MapEntry $staleMap;

    protected function setUp(): void
    {
        parent::setUp();

        $sheet = Sheet::create(['name' => 'Event']);
        $this->term = SourceTerm::create(['sheet_id' => $sheet->id]);
        SourceTermCode::create(['source_term_id' => $this->term->id, 'spot' => 1, 'code' => 'E1', 'description' => 'BP taking']);

        // deprecated target + its standard replacement
        Concept::create(['concept_id' => 4020553, 'concept_name' => 'Blood pressure taking', 'domain_id' => 'Measurement', 'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Procedure', 'standard_concept' => 'S', 'concept_code' => '46973005', 'valid_start_date' => '20020131', 'valid_end_date' => '20250131', 'invalid_reason' => 'U']);
        Concept::create(['concept_id' => 4248525, 'concept_name' => 'Sitting blood pressure', 'domain_id' => 'Measurement', 'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Observable Entity', 'standard_concept' => 'S', 'concept_code' => '163035008', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231']);
        ConceptRelationship::create(['concept_id_1' => 4020553, 'concept_id_2' => 4248525, 'relationship_id' => 'Concept replaced by', 'valid_start_date' => '20250131', 'valid_end_date' => '20991231']);
        ConceptRelationship::create(['concept_id_1' => 4020553, 'concept_id_2' => 4248525, 'relationship_id' => 'Maps to', 'valid_start_date' => '20250131', 'valid_end_date' => '20991231']);

        $this->staleMap = MapEntry::create([
            'source_term_id' => $this->term->id, 'target_concept_id' => 4020553,
            'target_concept_name' => 'Blood pressure taking', 'target_vocabulary_id' => 'SNOMED',
        ]);
    }

    private function reviewer(): User
    {
        return User::factory()->create(['enabled' => true, 'is_reviewer' => true]);
    }

    public function test_deprecated_target_listed_with_replacement(): void
    {
        Livewire::actingAs($this->reviewer())->test(VocabImpactReport::class)
            ->assertSee('Blood pressure taking')
            ->assertSee('deprecated (invalid_reason = U)')
            ->assertSee('Sitting blood pressure')
            ->assertSee('Remap to 163035008');
    }

    public function test_one_click_remap_updates_and_audits(): void
    {
        $reviewer = $this->reviewer();

        Livewire::actingAs($reviewer)->test(VocabImpactReport::class)
            ->call('remap', $this->staleMap->id, 4248525)
            ->assertSee('Remapped map');

        $this->assertDatabaseHas('maps', ['id' => $this->staleMap->id, 'target_concept_id' => 4248525]);
        $this->assertDatabaseHas('map_audits', [
            'map_id' => $this->staleMap->id, 'action' => 'Update',
            'before_target_concept_id' => 4020553, 'target_concept_id' => 4248525,
        ]);
    }

    public function test_send_to_question(): void
    {
        Livewire::actingAs($this->reviewer())->test(VocabImpactReport::class)
            ->call('sendToQuestion', $this->term->id)
            ->assertSee('flagged as Question');

        $this->assertDatabaseHas('source_terms', ['id' => $this->term->id, 'exclude_status' => 'Question - Pending']);
    }

    public function test_missing_from_vocabulary_flagged(): void
    {
        MapEntry::create([
            'source_term_id' => $this->term->id, 'target_concept_id' => 99999999,
            'target_concept_name' => 'Gone', 'target_vocabulary_id' => 'SNOMED',
        ]);

        Livewire::actingAs($this->reviewer())->test(VocabImpactReport::class)
            ->assertSee('missing from vocabulary');
    }

    public function test_non_reviewer_forbidden(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);
        Livewire::actingAs($mapper)->test(VocabImpactReport::class)->assertForbidden();
    }
}
