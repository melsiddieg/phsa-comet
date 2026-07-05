<?php

namespace App\Livewire;

use App\Services\ReviewService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reviewer queue: terms with pending map changes, with a before/after view and
 * one-click approval that snapshots the term's current targets.
 */
class ReviewQueue extends Component
{
    use WithPagination;

    public ?int $inspectingTermId = null;

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('review');
    }

    public function inspect(int $termId): void
    {
        $this->inspectingTermId = $termId;
    }

    public function closeInspect(): void
    {
        $this->inspectingTermId = null;
    }

    public function approve(int $termId, ReviewService $reviews): void
    {
        $this->authorize('review');

        $count = $reviews->approve($termId, auth()->user()->name);
        $this->message = "Approved $count pending change(s).";
        $this->inspectingTermId = null;
        $this->resetPage();
    }

    public function render(ReviewService $reviews): View
    {
        $inspect = null;
        if ($this->inspectingTermId) {
            $before = $reviews->lastApprovedTargets($this->inspectingTermId);
            $inspect = [
                'term' => $reviews->term($this->inspectingTermId),
                'changes' => $reviews->pendingChanges($this->inspectingTermId),
                'before' => $before,
            ];
        }

        return view('livewire.review-queue', [
            'terms' => $reviews->pendingTerms(),
            'inspect' => $inspect,
        ]);
    }
}
