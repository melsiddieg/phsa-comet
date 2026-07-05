<?php

namespace App\Livewire;

use App\Services\ConceptDetail;
use App\Services\ConceptSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Standalone vocabulary browser (/concepts): the same ranked engine as the
 * editor, over the whole vocabulary, with a concept-detail slide-over showing
 * synonyms, relationships, and hierarchy.
 */
class ConceptBrowser extends Component
{
    #[Url] public string $q = '';
    #[Url] public string $vocab = 'all';
    #[Url] public string $domain = 'all';
    public bool $standardOnly = true;
    public bool $validOnly = true;

    public ?int $detailId = null;

    public function showDetail(int $conceptId): void
    {
        $this->detailId = $conceptId;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    public function render(ConceptSearch $search, ConceptDetail $detail): View
    {
        $results = $this->q !== ''
            ? $search->search($this->q, $this->vocab === 'all' ? [] : [$this->vocab], $this->domain, $this->standardOnly, $this->validOnly)
            : collect();

        return view('livewire.concept-browser', [
            'results' => $results,
            'detail' => $this->detailId ? $detail->for($this->detailId) : null,
        ]);
    }
}
