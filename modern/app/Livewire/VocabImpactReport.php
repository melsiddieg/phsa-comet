<?php

namespace App\Livewire;

use App\Models\Concept;
use App\Models\MapEntry;
use App\Models\SourceTerm;
use App\Services\MapAuditor;
use App\Services\VocabImpact;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Post-refresh impact report (reviewer role): maps whose target became
 * deprecated/non-standard/missing, with one-click remap to a suggested
 * replacement (audited) or send-to-Question.
 */
class VocabImpactReport extends Component
{
    public string $message = '';

    public function mount(): void
    {
        $this->authorize('review');
    }

    public function remap(int $mapId, int $newConceptId): void
    {
        $this->authorize('review');

        $concept = Concept::where('concept_id', $newConceptId)->first();
        if (! $concept || ! $concept->isValidMapTarget()) {
            $this->message = 'Replacement is not a valid standard target.';

            return;
        }

        $map = MapEntry::find($mapId);
        if (! $map) {
            $this->message = 'Map no longer exists.';

            return;
        }

        $before = $map->target_concept_id;
        $map->update([
            'target_concept_id' => $concept->concept_id,
            'target_concept_name' => $concept->concept_name,
            'target_vocabulary_id' => $concept->vocabulary_id,
            'updated_by' => auth()->user()->name,
        ]);
        app(MapAuditor::class)->logMapChange('Update', $map, auth()->user()->name, $before);

        $this->message = "Remapped map #{$mapId} to {$concept->concept_name}.";
    }

    public function sendToQuestion(int $termId): void
    {
        $this->authorize('review');

        $term = SourceTerm::find($termId);
        if (! $term) {
            return;
        }
        $term->update(['exclude_status' => 'Question - Pending', 'updated_by' => auth()->user()->name]);
        app(MapAuditor::class)->logStatusChange('Question', $term, auth()->user()->name);
        $this->message = "Source term #{$termId} flagged as Question - Pending.";
    }

    public function render(VocabImpact $impact): View
    {
        return view('livewire.vocab-impact-report', [
            'vocabRelease' => DB::table('vocab_meta')->latest('id')->value('athena_release'),
            'staleCount' => $impact->staleCount(),
            'staleMaps' => $impact->staleMaps(),
        ]);
    }
}
