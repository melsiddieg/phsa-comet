<?php

namespace Tests\Feature;

use App\Livewire\ProgressSummary;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SourceTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_overall_progress_and_updates(): void
    {
        $sheet = Sheet::create(['name' => 'Location']);
        // 1 mapped, 1 unmapped, 1 excluded
        $mapped = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 100]);
        MapEntry::create(['source_term_id' => $mapped->id, 'target_concept_id' => 1, 'target_concept_name' => 'X', 'target_vocabulary_id' => 'V']);
        SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 50]);
        SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 10, 'exclude_status' => 'Out of Scope - Exclude']);

        $user = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        $component = Livewire::actingAs($user)->test(ProgressSummary::class)
            ->assertSee('Mapping progress')
            ->assertSee('33%')           // 1 of 3 terms mapped
            ->assertSee('1 / 3 terms mapped')
            ->assertSee('Location');

        // a new map moves the needle after refresh
        $new = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 5]);
        MapEntry::create(['source_term_id' => $new->id, 'target_concept_id' => 2, 'target_concept_name' => 'Y', 'target_vocabulary_id' => 'V']);

        $component->call('refresh')->assertSee('50%'); // 2 of 4 mapped
    }

    public function test_home_page_embeds_the_widget(): void
    {
        $user = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Mapping progress')
            ->assertSee('Welcome to COMET');
    }

    public function test_home_page_shows_modernization_milestones(): void
    {
        $done = collect(config('comet.milestones'))->where('status', 'done')->count();
        $total = count(config('comet.milestones'));

        $user = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Modernization status')
            ->assertSee("$done / $total milestones complete")
            ->assertSee('M0')
            ->assertSee('M7');
    }
}
