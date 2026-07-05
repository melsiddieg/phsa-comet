<?php

namespace Tests\Feature;

use App\Livewire\ConceptBrowser;
use App\Models\Concept;
use App\Models\ConceptSynonym;
use App\Models\MapEntry;
use App\Models\SourceTerm;
use App\Models\User;
use App\Services\ConceptSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ConceptSearchTest extends TestCase
{
    use RefreshDatabase;

    private function concept(int $id, string $name, string $vocab = 'SNOMED', ?string $std = 'S'): Concept
    {
        return Concept::create([
            'concept_id' => $id, 'concept_name' => $name, 'domain_id' => 'Measurement',
            'vocabulary_id' => $vocab, 'concept_class_id' => 'Observable Entity', 'standard_concept' => $std,
            'concept_code' => (string) $id, 'valid_start_date' => '20020131', 'valid_end_date' => '20991231',
        ]);
    }

    public function test_search_finds_by_name_and_synonym(): void
    {
        $this->concept(1, 'Systolic blood pressure');
        ConceptSynonym::create(['concept_id' => 1, 'concept_synonym_name' => 'SBP', 'language_concept_id' => 4180186]);
        $this->concept(2, 'Heart rate');

        $results = app(ConceptSearch::class)->search('blood pressure');
        $this->assertTrue($results->contains('concept_id', 1));
        $this->assertFalse($results->contains('concept_id', 2));
    }

    public function test_exact_code_ranks_first(): void
    {
        $this->concept(271649006, 'Systolic blood pressure');
        $this->concept(999, 'Blood pressure something');

        $top = app(ConceptSearch::class)->search('271649006')->first();
        $this->assertEquals(271649006, $top->concept_id);
        $this->assertEquals('code', $top->matched_on);
    }

    public function test_standard_only_filter(): void
    {
        $this->concept(1, 'Blood pressure reading', 'SNOMED', 'S');
        $this->concept(2, 'Blood pressure legacy', 'SNOMED', null);

        $std = app(ConceptSearch::class)->search('blood pressure', [], 'all', true, true);
        $this->assertFalse($std->contains('concept_id', 2));

        $all = app(ConceptSearch::class)->search('blood pressure', [], 'all', false, true);
        $this->assertTrue($all->contains('concept_id', 2));
    }

    public function test_usage_tiebreak_is_reported(): void
    {
        $this->concept(1, 'Blood pressure alpha');
        $this->concept(2, 'Blood pressure beta');
        $term = SourceTerm::create(['sheet_id' => \App\Models\Sheet::create(['name' => 'S'])->id]);
        MapEntry::create(['source_term_id' => $term->id, 'target_concept_id' => 2, 'target_concept_name' => 'Blood pressure beta', 'target_vocabulary_id' => 'SNOMED']);

        $results = app(ConceptSearch::class)->search('blood pressure');
        $beta = $results->firstWhere('concept_id', 2);
        $this->assertEquals(1, $beta->team_uses);
    }

    public function test_browser_page_and_detail_open(): void
    {
        $this->concept(1, 'Systolic blood pressure');
        ConceptSynonym::create(['concept_id' => 1, 'concept_synonym_name' => 'SBP', 'language_concept_id' => 4180186]);
        // hierarchy: concept 1 is a child of concept 44 (parent)
        $this->concept(44, 'Blood pressure reading');
        DB::table('concept_ancestors')->insert(['ancestor_concept_id' => 44, 'descendant_concept_id' => 1, 'min_levels_of_separation' => 1, 'max_levels_of_separation' => 1]);

        $user = User::factory()->create(['enabled' => true, 'is_mapper' => true]);

        Livewire::actingAs($user)->test(ConceptBrowser::class)
            ->set('q', 'blood pressure')
            ->assertSee('Systolic blood pressure')
            ->call('showDetail', 1)
            ->assertSee('SBP')                       // synonym in detail
            ->assertSee('Blood pressure reading');   // parent via ancestors
    }
}
