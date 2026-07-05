<?php

namespace App\Livewire;

use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Paginated per-sheet mapping grid (the legacy list_mr.php), with the same
 * filters. Read path for M2; the slide-over editor arrives in M3.
 */
class MappingGrid extends Component
{
    use WithPagination;

    public Sheet $sheet;

    #[Url] public string $status = 'all';   // all|i|e|q|s|p  (include/exclude/question/sdo-send/sdo-pending)
    #[Url] public string $mapped = 'all';   // all|m|a|n      (mapped/auto/not-mapped)
    #[Url] public string $vocab = 'all';
    #[Url] public string $domain = 'all';
    #[Url] public string $q = '';           // source-description search

    public ?int $editingTermId = null;

    public function mount(Sheet $sheet): void
    {
        $this->sheet = $sheet;
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function openEditor(int $termId): void
    {
        $this->editingTermId = $termId;
    }

    #[On('editor-closed')]
    public function closeEditor(): void
    {
        $this->editingTermId = null;
    }

    #[On('map-saved')]
    public function refreshGrid(): void
    {
        // re-render (grid queries re-run); keep the editor open
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'mapped', 'vocab', 'domain', 'q']);
        $this->status = $this->mapped = $this->vocab = $this->domain = 'all';
        $this->resetPage();
    }

    public function render(): View
    {
        $spots = $this->sheet->sourceColumns; // ordered by spot

        // Base: one row per source term in this sheet.
        $query = DB::table('source_terms as t')
            ->where('t.sheet_id', $this->sheet->id)
            ->select('t.id', 't.total_count', 't.map_source', 't.mr_status', 't.exclude_status')
            ->selectSub(
                DB::table('maps')->selectRaw('count(*)')->whereColumn('maps.source_term_id', 't.id'),
                'map_count'
            );

        // ── Status filter
        match ($this->status) {
            'i' => $query->whereNull('t.exclude_status'),
            'e' => $query->where('t.exclude_status', 'Out of Scope - Exclude'),
            'q' => $query->where('t.exclude_status', 'Question - Pending'),
            's' => $query->where('t.exclude_status', 'SDO Submission - Send'),
            'p' => $query->where('t.exclude_status', 'SDO Submitted - Pending'),
            default => null,
        };

        // ── Mapped filter
        match ($this->mapped) {
            'm' => $query->whereExists(fn ($q) => $q->from('maps')->whereColumn('maps.source_term_id', 't.id')),
            'a' => $query->where('t.map_source', 'Auto'),
            'n' => $query->whereNotExists(fn ($q) => $q->from('maps')->whereColumn('maps.source_term_id', 't.id')),
            default => null,
        };

        // ── Source-description search (spot-1)
        if ($this->q !== '') {
            $needle = '%'.strtolower($this->q).'%';
            $query->whereExists(fn ($q) => $q->from('source_term_codes as sc')
                ->whereColumn('sc.source_term_id', 't.id')
                ->where('sc.spot', 1)
                ->whereRaw('lower(sc.description) like ?', [$needle]));
        }

        // ── Vocabulary / domain filter (on the chosen map target)
        if ($this->vocab !== 'all') {
            $query->whereExists(fn ($q) => $q->from('maps')
                ->whereColumn('maps.source_term_id', 't.id')
                ->where('maps.target_vocabulary_id', $this->vocab));
        }
        if ($this->domain !== 'all') {
            $query->whereExists(fn ($q) => $q->from('maps')
                ->join('concepts', 'concepts.concept_id', '=', 'maps.target_concept_id')
                ->whereColumn('maps.source_term_id', 't.id')
                ->where('concepts.domain_id', $this->domain));
        }

        $terms = $query->orderByDesc('t.total_count')->paginate(50);

        // Enrich the visible page: source codes + the reconciled map/suggested target.
        $ids = collect($terms->items())->pluck('id')->all();
        $codes = DB::table('source_term_codes')->whereIn('source_term_id', $ids)
            ->orderBy('spot')->get()->groupBy('source_term_id');
        $maps = DB::table('maps')
            ->leftJoin('concepts', 'concepts.concept_id', '=', 'maps.target_concept_id')
            ->whereIn('maps.source_term_id', $ids)
            ->get(['maps.source_term_id', 'maps.target_concept_id', 'maps.target_concept_name',
                'maps.target_vocabulary_id', 'concepts.concept_code', 'concepts.domain_id',
                'concepts.standard_concept', 'concepts.invalid_reason'])
            ->groupBy('source_term_id');

        return view('livewire.mapping-grid', [
            'terms' => $terms,
            'spots' => $spots,
            'codes' => $codes,
            'mapsByTerm' => $maps,
            'vocabOptions' => $this->sheet->vocabularyNames()->orderBy('vocabulary')->pluck('vocabulary'),
            'domainOptions' => $this->sheet->domains()->orderBy('domain_id')->pluck('domain_id'),
        ]);
    }
}
