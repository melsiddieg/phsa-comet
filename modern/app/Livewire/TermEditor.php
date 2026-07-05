<?php

namespace App\Livewire;

use App\Models\MapEntry;
use App\Models\SourceTerm;
use App\Models\SourceTermComment;
use App\Services\ConceptSearch;
use App\Services\MapAuditor;
use App\Services\MapGuardrails;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Slide-over editor for one source term: view details, add/update/delete maps
 * (with CDM v5.4 guardrails + replacement suggestions), concept search over
 * names+synonyms, duplicate-map propagation, exclusion/Question/SDO statuses,
 * comment, and the term's change history. Every write is audited.
 */
class TermEditor extends Component
{
    public SourceTerm $term;

    // Add-map form
    public string $newCode = '';
    public string $newVocabulary = '';

    // Concept search panel
    public string $searchQuery = '';
    public string $searchDomain = 'all';
    public string $searchVocab = 'sheet';
    public bool $searchStandardOnly = true;
    public bool $searchValidOnly = true;
    public bool $searchRan = false;

    // Status panel
    public string $excludeStatus = '';
    public string $commentText = '';

    public string $message = '';
    public string $messageType = 'ok'; // ok | error | warn

    /** @var array<int, array<string,mixed>> replacement suggestions after a guardrail rejection */
    public array $replacements = [];

    public function mount(int $termId): void
    {
        $this->term = SourceTerm::with(['codes', 'sheet.sourceColumns', 'maps', 'comment'])->findOrFail($termId);
        $this->searchQuery = (string) ($this->term->codes->firstWhere('spot', 1)->description ?? '');
        $this->newVocabulary = (string) $this->term->sheet->vocabularyNames()->orderBy('vocabulary')->value('vocabulary');
        $this->excludeStatus = (string) $this->term->exclude_status;
        $this->commentText = (string) ($this->term->comment->comment_text ?? '');
    }

    // ── Maps ────────────────────────────────────────────────────────────

    public function addMap(?string $code = null, ?string $vocabulary = null): void
    {
        $this->authorize('map');
        $this->replacements = [];

        $code = trim($code ?? $this->newCode);
        $vocabulary = $vocabulary ?? $this->newVocabulary;

        if ($code === '') {
            $this->flash('Please specify a concept code.', 'error');

            return;
        }
        if (! $this->term->sheet->vocabularyNames()->where('vocabulary', $vocabulary)->exists()) {
            $this->flash("Vocabulary '$vocabulary' is not allowed for this sheet.", 'error');

            return;
        }
        if ($this->term->maps->contains(fn (MapEntry $m) => $m->targetConcept?->concept_code === $code)) {
            $this->flash('This target already exists on the term.', 'error');

            return;
        }

        $guard = app(MapGuardrails::class);
        $concept = $guard->resolve($code, $vocabulary);

        if (! $concept) {
            $this->flash("Concept code '$code' not found in vocabulary '$vocabulary'.", 'error');

            return;
        }
        if (! $guard->isValidTarget($concept)) {
            $this->rejectTarget($concept, $guard);

            return;
        }

        $map = MapEntry::create([
            'source_term_id' => $this->term->id,
            'target_concept_id' => $concept->concept_id,
            'target_concept_name' => $concept->concept_name,
            'target_vocabulary_id' => $vocabulary,
            'created_by' => auth()->user()->name,
        ]);
        app(MapAuditor::class)->logMapChange('Add', $map, auth()->user()->name);

        $this->newCode = '';
        $this->refreshTerm();
        $this->dispatch('map-saved');
        $this->flash($this->domainWarning($concept) ?? 'Map added.', $this->domainWarning($concept) ? 'warn' : 'ok');
    }

    public function updateMap(int $mapId, string $code, string $vocabulary): void
    {
        $this->authorize('map');
        $this->replacements = [];

        $map = MapEntry::where('source_term_id', $this->term->id)->findOrFail($mapId);

        $guard = app(MapGuardrails::class);
        $concept = $guard->resolve(trim($code), $vocabulary);

        if (! $concept) {
            $this->flash("Concept code '$code' not found in vocabulary '$vocabulary'.", 'error');

            return;
        }
        if (! $guard->isValidTarget($concept)) {
            $this->rejectTarget($concept, $guard);

            return;
        }

        $before = $map->target_concept_id;
        $map->update([
            'target_concept_id' => $concept->concept_id,
            'target_concept_name' => $concept->concept_name,
            'target_vocabulary_id' => $vocabulary,
            'updated_by' => auth()->user()->name,
        ]);
        app(MapAuditor::class)->logMapChange('Update', $map, auth()->user()->name, $before);

        $this->refreshTerm();
        $this->dispatch('map-saved');
        $this->flash($this->domainWarning($concept) ?? 'Map updated.', $this->domainWarning($concept) ? 'warn' : 'ok');
    }

    public function deleteMap(int $mapId): void
    {
        $this->authorize('map');

        $map = MapEntry::where('source_term_id', $this->term->id)->findOrFail($mapId);
        app(MapAuditor::class)->logMapChange('Delete', $map, auth()->user()->name);
        $map->delete();

        $this->refreshTerm();
        $this->dispatch('map-saved');
        $this->flash('Map deleted.');
    }

    // ── Duplicate-map propagation ──────────────────────────────────────

    public function propagate(int $conceptId): void
    {
        $this->authorize('map');

        $concept = DB::table('concepts')->where('concept_id', $conceptId)->first();
        if (! $concept || $concept->standard_concept !== 'S' || $concept->invalid_reason !== null) {
            $this->flash('Cannot propagate a non-standard or invalid concept.', 'error');

            return;
        }
        if (! $this->term->sheet->vocabularyNames()->where('vocabulary', $concept->vocabulary_id)->exists()) {
            $this->flash("Vocabulary '{$concept->vocabulary_id}' is not allowed for this sheet.", 'error');

            return;
        }

        $auditor = app(MapAuditor::class);
        $applied = 0;
        foreach ($this->unmappedTwins() as $twin) {
            $map = MapEntry::create([
                'source_term_id' => $twin->id,
                'target_concept_id' => $concept->concept_id,
                'target_concept_name' => $concept->concept_name,
                'target_vocabulary_id' => $concept->vocabulary_id,
                'created_by' => auth()->user()->name,
            ]);
            $auditor->logMapChange('Add', $map, auth()->user()->name);
            $applied++;
        }

        $this->refreshTerm();
        $this->dispatch('map-saved');
        $this->flash("Applied '{$concept->concept_name}' to $applied identical unmapped term(s) in this sheet.");
    }

    // ── Status / comment ───────────────────────────────────────────────

    public function saveStatus(): void
    {
        $this->authorize('map');

        $allowed = ['', 'Out of Scope - Exclude', 'Question - Pending', 'SDO Submission - Send', 'SDO Submitted - Pending'];
        if (! in_array($this->excludeStatus, $allowed, true)) {
            $this->flash('Invalid status.', 'error');

            return;
        }

        $action = match ($this->excludeStatus) {
            '' => 'Include',
            'Out of Scope - Exclude' => 'Exclude',
            'Question - Pending' => 'Question',
            'SDO Submission - Send' => 'Set to SDO Submission',
            'SDO Submitted - Pending' => 'Sent to SDO',
        };

        $this->term->update([
            'exclude_status' => $this->excludeStatus ?: null,
            'updated_by' => auth()->user()->name,
        ]);

        SourceTermComment::updateOrCreate(
            ['source_term_id' => $this->term->id],
            ['comment_text' => $this->commentText, 'updated_by' => auth()->user()->name]
        );

        app(MapAuditor::class)->logStatusChange($action, $this->term, auth()->user()->name);

        $this->refreshTerm();
        $this->dispatch('map-saved');
        $this->flash('Status updated.');
    }

    // ── Search ─────────────────────────────────────────────────────────

    public function runSearch(): void
    {
        $this->searchRan = true;
    }

    public int $detailConceptId = 0;

    public function showDetail(int $conceptId): void
    {
        $this->detailConceptId = $conceptId;
    }

    public function closeDetail(): void
    {
        $this->detailConceptId = 0;
    }

    public function useConcept(string $code, string $vocabulary): void
    {
        $this->newCode = $code;
        if ($this->term->sheet->vocabularyNames()->where('vocabulary', $vocabulary)->exists()) {
            $this->newVocabulary = $vocabulary;
        }
    }

    public function close(): void
    {
        $this->dispatch('editor-closed');
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function rejectTarget($concept, MapGuardrails $guard): void
    {
        $reason = $concept->invalid_reason !== null
            ? "is no longer valid (invalid_reason = '{$concept->invalid_reason}')"
            : 'is not a standard concept';
        $this->flash("Cannot map to '{$concept->concept_name}' ({$concept->concept_code}) — it $reason.", 'error');
        $this->replacements = $guard->standardReplacements($concept->concept_id)->map(fn ($r) => (array) $r)->all();
    }

    private function domainWarning($concept): ?string
    {
        $domains = $this->term->sheet->domains()->pluck('domain_id');
        if ($domains->isNotEmpty() && ! $domains->contains($concept->domain_id)) {
            return "Saved. Note: target domain '{$concept->domain_id}' is unusual for this sheet (expected: {$domains->implode(', ')}).";
        }

        return null;
    }

    private function refreshTerm(): void
    {
        $this->term = SourceTerm::with(['codes', 'sheet.sourceColumns', 'maps', 'comment'])->findOrFail($this->term->id);
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $this->message = $message;
        $this->messageType = $type;
    }

    /** Existing maps elsewhere for the same normalized spot-1 description. */
    private function mappedElsewhere()
    {
        $desc = strtolower(trim((string) ($this->term->codes->firstWhere('spot', 1)->description ?? '')));
        if ($desc === '') {
            return collect();
        }

        return DB::table('maps as m')
            ->join('source_term_codes as sc', fn ($j) => $j->on('sc.source_term_id', '=', 'm.source_term_id')->where('sc.spot', 1))
            ->leftJoin('concepts as c', 'c.concept_id', '=', 'm.target_concept_id')
            ->whereRaw('lower(trim(sc.description)) = ?', [$desc])
            ->where('m.source_term_id', '!=', $this->term->id)
            ->groupBy('m.target_concept_id', 'm.target_concept_name', 'm.target_vocabulary_id', 'c.concept_code', 'c.standard_concept', 'c.invalid_reason')
            ->selectRaw('m.target_concept_id, m.target_concept_name, m.target_vocabulary_id,
                         c.concept_code, c.standard_concept, c.invalid_reason,
                         count(distinct m.source_term_id) as used_count')
            ->orderByDesc('used_count')
            ->get();
    }

    /** Unmapped, unexcluded terms in this sheet with the same spot-1 description. */
    private function unmappedTwins()
    {
        $desc = strtolower(trim((string) ($this->term->codes->firstWhere('spot', 1)->description ?? '')));
        if ($desc === '') {
            return collect();
        }

        return SourceTerm::query()
            ->where('sheet_id', $this->term->sheet_id)
            ->whereNull('exclude_status')
            ->whereDoesntHave('maps')
            ->whereHas('codes', fn ($q) => $q->where('spot', 1)->whereRaw('lower(trim(description)) = ?', [$desc]))
            ->get();
    }

    public function render(ConceptSearch $search, \App\Services\ConceptDetail $conceptDetail): View
    {
        $sheetVocabs = $this->term->sheet->vocabularyNames()->orderBy('vocabulary')->pluck('vocabulary');

        $vocabFilter = match ($this->searchVocab) {
            'sheet' => $sheetVocabs->all(),
            'all' => [],
            default => [$this->searchVocab],
        };

        $results = $this->searchRan || $this->searchQuery !== ''
            ? $search->search($this->searchQuery, $vocabFilter, $this->searchDomain, $this->searchStandardOnly, $this->searchValidOnly)
            : collect();

        $twins = $this->unmappedTwins();

        return view('livewire.term-editor', [
            'sheetVocabs' => $sheetVocabs,
            'sheetDomains' => $this->term->sheet->domains()->orderBy('domain_id')->pluck('domain_id'),
            'searchResults' => $results,
            'elsewhere' => $this->mappedElsewhere(),
            'twinCount' => $twins->count(),
            'history' => DB::table('map_audits')->where('source_term_id', $this->term->id)->orderByDesc('created_at')->limit(50)->get(),
            'attributes' => DB::table('source_term_attributes as a')
                ->join('sheet_attributes as sa', 'sa.id', '=', 'a.sheet_attribute_id')
                ->where('a.source_term_id', $this->term->id)
                ->orderBy('sa.col_position')->get(['sa.name', 'a.value']),
            'detail' => $this->detailConceptId ? $conceptDetail->for($this->detailConceptId) : null,
        ]);
    }
}
