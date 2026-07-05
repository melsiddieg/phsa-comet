<?php

namespace Tests\Feature;

use App\Livewire\ReviewQueue;
use App\Livewire\TermEditor;
use App\Models\MapAudit;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SheetVocabulary;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use App\Services\ClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Sheet $sheet;

    private SourceTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = Sheet::create(['name' => 'Event']);
        SheetVocabulary::create(['sheet_id' => $this->sheet->id, 'vocabulary' => 'SNOMED']);
        $this->term = SourceTerm::create(['sheet_id' => $this->sheet->id]);
        SourceTermCode::create(['source_term_id' => $this->term->id, 'spot' => 1, 'code' => 'E1', 'description' => 'BP']);
    }

    public function test_claim_and_takeover_after_expiry(): void
    {
        $svc = app(ClaimService::class);

        $this->assertEquals('alice', $svc->claim($this->term, 'alice'));
        $this->term->refresh();
        // fresh claim by alice: bob can't take it
        $this->assertTrue($svc->heldByOther($this->term, 'bob'));

        // expire it
        $this->term->update(['claimed_at' => now()->subHours(config('comet.claim_ttl_hours') + 1)]);
        $this->term->refresh();
        $this->assertFalse($svc->heldByOther($this->term, 'bob'));
        $this->assertEquals('bob', $svc->claim($this->term, 'bob'));
    }

    public function test_editor_claims_on_open_and_releases_on_close(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true, 'name' => 'Mia']);

        Livewire::actingAs($mapper)->test(TermEditor::class, ['termId' => $this->term->id])
            ->assertSet('claimNote', '')
            ->call('close');

        $this->assertNull($this->term->fresh()->claimed_by);
    }

    public function test_request_changes_returns_to_mapper_and_clears_on_resave(): void
    {
        // a pending change by the mapper
        $this->term->update(['updated_by' => 'Mia']);
        $map = MapEntry::create(['source_term_id' => $this->term->id, 'target_concept_id' => 1, 'target_concept_name' => 'x', 'target_vocabulary_id' => 'SNOMED']);
        MapAudit::create(['map_id' => $map->id, 'source_term_id' => $this->term->id, 'action' => 'Add', 'username' => 'Mia', 'approved_by' => null, 'created_at' => now()]);

        $reviewer = User::factory()->create(['enabled' => true, 'is_reviewer' => true, 'name' => 'Rev']);

        // reviewer requests changes (comment required)
        Livewire::actingAs($reviewer)->test(ReviewQueue::class)
            ->call('inspect', $this->term->id)
            ->call('requestChanges', $this->term->id)   // no comment yet
            ->assertSee('comment is required')
            ->set('returnComment', 'Please use the sitting variant')
            ->call('requestChanges', $this->term->id)
            ->assertSee('Returned to the mapper');

        $this->term->refresh();
        $this->assertEquals('returned', $this->term->review_state);
        $this->assertEquals('Mia', $this->term->returned_to);
        // queue cleared (audit stamped)
        $this->assertEquals(0, app(\App\Services\ReviewService::class)->pendingCount());

        // mapper re-saves -> returned flag clears
        \App\Models\Concept::create(['concept_id' => 2, 'concept_name' => 'Sitting BP', 'domain_id' => 'Measurement', 'vocabulary_id' => 'SNOMED', 'concept_class_id' => 'Observable Entity', 'standard_concept' => 'S', 'concept_code' => '163035008', 'valid_start_date' => '20020131', 'valid_end_date' => '20991231']);
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true, 'name' => 'Mia2']);
        Livewire::actingAs($mapper)->test(TermEditor::class, ['termId' => $this->term->id])
            ->set('newCode', '163035008')->set('newVocabulary', 'SNOMED')->call('addMap');

        $this->assertNull($this->term->fresh()->review_state);
    }

    public function test_bulk_approve(): void
    {
        $terms = [];
        foreach (range(1, 3) as $i) {
            $t = SourceTerm::create(['sheet_id' => $this->sheet->id]);
            $m = MapEntry::create(['source_term_id' => $t->id, 'target_concept_id' => $i, 'target_concept_name' => 'x', 'target_vocabulary_id' => 'SNOMED']);
            MapAudit::create(['map_id' => $m->id, 'source_term_id' => $t->id, 'action' => 'Add', 'username' => 'Mia', 'approved_by' => null, 'created_at' => now()]);
            $terms[] = $t->id;
        }
        $reviewer = User::factory()->create(['enabled' => true, 'is_reviewer' => true]);

        Livewire::actingAs($reviewer)->test(ReviewQueue::class)
            ->set('selected', $terms)
            ->call('bulkApprove')
            ->assertSee('Bulk-approved 3');

        $this->assertEquals(0, app(\App\Services\ReviewService::class)->pendingCount());
    }

    public function test_team_dashboard_requires_reviewer(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true, 'is_reviewer' => false]);
        $this->actingAs($mapper)->get('/team')->assertForbidden();

        $reviewer = User::factory()->create(['enabled' => true, 'is_reviewer' => true]);
        $this->actingAs($reviewer)->get('/team')->assertOk()->assertSee('Team Dashboard');
    }
}
