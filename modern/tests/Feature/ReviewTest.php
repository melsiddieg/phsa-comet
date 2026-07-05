<?php

namespace Tests\Feature;

use App\Livewire\ReviewQueue;
use App\Models\MapAudit;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private Sheet $sheet;

    private SourceTerm $term;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sheet = Sheet::create(['name' => 'Location']);
        $this->term = SourceTerm::create(['sheet_id' => $this->sheet->id]);
        SourceTermCode::create(['source_term_id' => $this->term->id, 'spot' => 1, 'code' => 'A1', 'description' => 'Lions Gate']);
    }

    private function pendingChange(): MapEntry
    {
        $map = MapEntry::create([
            'source_term_id' => $this->term->id, 'target_concept_id' => 8717,
            'target_concept_name' => 'Inpatient Hospital', 'target_vocabulary_id' => 'CMS Place of Service',
        ]);
        MapAudit::create([
            'map_id' => $map->id, 'source_term_id' => $this->term->id, 'action' => 'Add',
            'target_concept_id' => 8717, 'target_concept_name' => 'Inpatient Hospital',
            'username' => 'mapper', 'approved_by' => null, 'created_at' => now(),
        ]);

        return $map;
    }

    public function test_pending_change_appears_and_grandfathered_history_does_not(): void
    {
        $this->pendingChange();
        // a grandfathered historical audit must NOT show as pending
        MapAudit::create([
            'map_id' => null, 'source_term_id' => $this->term->id, 'action' => 'Add',
            'username' => 'old', 'approved_by' => 'migration', 'created_at' => now()->subYear(),
        ]);

        $reviews = app(ReviewService::class);
        $this->assertEquals(1, $reviews->pendingCount());
        $this->assertCount(1, $reviews->pendingChanges($this->term->id));
    }

    public function test_reviewer_can_approve_and_it_snapshots(): void
    {
        $this->pendingChange();
        $reviewer = User::factory()->create(['enabled' => true, 'is_reviewer' => true]);

        Livewire::actingAs($reviewer)->test(ReviewQueue::class)
            ->assertSee('#'.$this->term->id)
            ->call('approve', $this->term->id)
            ->assertSee('Approved 1 pending change');

        $this->assertDatabaseHas('map_audits', [
            'source_term_id' => $this->term->id, 'approved_by' => $reviewer->name,
        ]);
        $this->assertDatabaseHas('map_snapshots', [
            'source_term_id' => $this->term->id, 'concept_id' => 8717, 'approved_by' => $reviewer->name,
        ]);
        // queue now empty
        $this->assertEquals(0, app(ReviewService::class)->pendingCount());
    }

    public function test_before_after_uses_last_snapshot(): void
    {
        $this->pendingChange();
        $reviewer = User::factory()->create(['enabled' => true, 'is_reviewer' => true]);
        $reviews = app(ReviewService::class);

        // approve once -> snapshot {8717}
        $reviews->approve($this->term->id, $reviewer->name);

        // change the map and add a new pending audit
        MapEntry::where('source_term_id', $this->term->id)->update(['target_concept_id' => 9999, 'target_concept_name' => 'X']);
        MapAudit::create([
            'map_id' => null, 'source_term_id' => $this->term->id, 'action' => 'Update',
            'target_concept_id' => 9999, 'username' => 'mapper', 'approved_by' => null, 'created_at' => now(),
        ]);

        $before = $reviews->lastApprovedTargets($this->term->id);
        $this->assertEquals([8717], $before->all());
    }

    public function test_non_reviewer_forbidden(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true, 'is_reviewer' => false]);
        Livewire::actingAs($mapper)->test(ReviewQueue::class)->assertForbidden();
    }
}
