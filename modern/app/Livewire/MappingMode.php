<?php

namespace App\Livewire;

use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SourceTerm;
use App\Services\ConceptSearch;
use App\Services\MapAuditor;
use App\Services\MapGuardrails;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Keyboard-first rapid mapping queue. Walks a sheet's unmapped, unexcluded
 * terms by usage, showing precomputed candidates (map_candidates, from
 * comet:auto-map) with a live-search fallback. Every write goes through the
 * same guardrails + audit as the editor.
 *
 * Keys (handled in the Blade via Alpine): 1-9 map candidate N · e exclude ·
 * q question · s SDO · k skip · / focus search.
 */
class MappingMode extends Component
{
    public Sheet $sheet;

    public ?int $termId = null;

    public string $search = '';

    public string $equivalence = '';

    public int $sessionMapped = 0;

    public string $flash = '';

    public function mount(Sheet $sheet): void
    {
        $this->authorize('map');
        $this->sheet = $sheet;
        $this->loadNext();
    }

    /** Next unmapped, unexcluded term by usage (skipping the current one). */
    public function loadNext(?int $after = null): void
    {
        $this->search = '';
        $this->termId = SourceTerm::query()
            ->where('sheet_id', $this->sheet->id)
            ->whereNull('exclude_status')
            ->whereDoesntHave('maps')
            ->when($after, fn ($q) => $q->where('id', '!=', $after))
            ->orderByDesc('total_count')
            ->value('id');
    }

    public function skip(): void
    {
        $this->loadNext($this->termId);
    }

    /** Keyboard: map the Nth (1-based) visible candidate. */
    public function pick(int $n): void
    {
        $term = $this->currentTerm();
        if (! $term) {
            return;
        }
        $candidate = $this->candidatesFor($term, app(ConceptSearch::class))->values()->get($n - 1);
        if ($candidate) {
            $this->mapCandidate((int) $candidate->concept_id);
        }
    }

    public function mapCandidate(int $conceptId): void
    {
        $this->authorize('map');
        $term = $this->currentTerm();
        if (! $term) {
            return;
        }

        $concept = DB::table('concepts')->where('concept_id', $conceptId)->first();
        $guard = app(MapGuardrails::class);
        if (! $concept || $concept->standard_concept !== 'S' || $concept->invalid_reason !== null) {
            $this->flash = 'That concept is not a standard, valid target.';

            return;
        }
        if (! $this->sheet->vocabularyNames()->where('vocabulary', $concept->vocabulary_id)->exists()) {
            $this->flash = "Vocabulary {$concept->vocabulary_id} not allowed for this sheet.";

            return;
        }

        $map = MapEntry::create([
            'source_term_id' => $term->id,
            'target_concept_id' => $concept->concept_id,
            'target_concept_name' => $concept->concept_name,
            'target_vocabulary_id' => $concept->vocabulary_id,
            'equivalence' => $this->equivalence ?: null,
            'created_by' => auth()->user()->name,
        ]);
        app(MapAuditor::class)->logMapChange('Add', $map, auth()->user()->name);

        $this->sessionMapped++;
        $this->flash = "Mapped → {$concept->concept_name}";
        $this->loadNext($term->id);
    }

    public function setStatus(string $status): void
    {
        $this->authorize('map');
        $term = $this->currentTerm();
        if (! $term) {
            return;
        }

        $map = [
            'exclude' => ['Out of Scope - Exclude', 'Exclude'],
            'question' => ['Question - Pending', 'Question'],
            'sdo' => ['SDO Submission - Send', 'Set to SDO Submission'],
        ][$status] ?? null;
        if (! $map) {
            return;
        }

        $term->update(['exclude_status' => $map[0], 'updated_by' => auth()->user()->name]);
        app(MapAuditor::class)->logStatusChange($map[1], $term, auth()->user()->name);
        $this->flash = "Set: {$map[0]}";
        $this->loadNext($term->id);
    }

    private function currentTerm(): ?SourceTerm
    {
        return $this->termId ? SourceTerm::with('codes')->find($this->termId) : null;
    }

    /** Ranked candidates for a term: precomputed, live-search override, or fallback. */
    private function candidatesFor(SourceTerm $term, ConceptSearch $search): \Illuminate\Support\Collection
    {
        $vocabs = $this->sheet->vocabularyNames()->pluck('vocabulary')->all();
        $desc = (string) ($term->codes->firstWhere('spot', 1)->description ?? '');

        if ($this->search !== '') {
            return $search->search($this->search, $vocabs, 'all', true, true, 9);
        }

        // precomputed candidates (fast path)
        $candidates = DB::table('map_candidates as mc')
            ->join('concepts as c', 'c.concept_id', '=', 'mc.concept_id')
            ->where('mc.source_term_id', $term->id)
            ->where('c.standard_concept', 'S')
            ->whereNull('c.invalid_reason')
            ->orderBy('mc.rank')
            ->limit(9)
            ->get(['c.concept_id', 'c.concept_name', 'c.concept_code', 'c.domain_id', 'c.vocabulary_id', 'mc.score', 'mc.matched_on']);

        // fallback to live search if none precomputed
        if ($candidates->isEmpty() && $desc !== '') {
            return $search->search($desc, $vocabs, 'all', true, true, 9);
        }

        return $candidates;
    }

    public function render(ConceptSearch $search): View
    {
        $term = $this->currentTerm();
        $candidates = $term ? $this->candidatesFor($term, $search) : collect();

        $remaining = SourceTerm::where('sheet_id', $this->sheet->id)
            ->whereNull('exclude_status')->whereDoesntHave('maps')->count();

        return view('livewire.mapping-mode', [
            'term' => $term,
            'candidates' => $candidates->values(),
            'remaining' => $remaining,
        ]);
    }
}
