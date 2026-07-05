<?php

namespace App\Livewire;

use App\Services\ReviewService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reviewer queue: terms with pending map changes. Approve (snapshots targets),
 * request changes (returns to the mapper with a comment), or bulk-approve;
 * filterable by sheet and age.
 */
class ReviewQueue extends Component
{
    use WithPagination;

    public ?int $inspectingTermId = null;

    public string $message = '';

    public string $returnComment = '';

    #[Url] public string $sheet = 'all';

    #[Url] public string $age = 'all'; // all | 7 | 30 (days)

    /** @var array<int, int> checked term ids for bulk approve */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('review');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function inspect(int $termId): void
    {
        $this->inspectingTermId = $termId;
        $this->returnComment = '';
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

    public function requestChanges(int $termId, ReviewService $reviews): void
    {
        $this->authorize('review');

        if (trim($this->returnComment) === '') {
            $this->message = 'A comment is required to request changes.';

            return;
        }

        $reviews->requestChanges($termId, auth()->user()->name, $this->returnComment);
        $this->message = 'Returned to the mapper with your comment.';
        $this->inspectingTermId = null;
        $this->returnComment = '';
        $this->resetPage();
    }

    public function bulkApprove(ReviewService $reviews): void
    {
        $this->authorize('review');

        $n = $reviews->approveMany($this->selected, auth()->user()->name);
        $this->message = "Bulk-approved $n term(s).";
        $this->selected = [];
        $this->resetPage();
    }

    public function render(ReviewService $reviews): View
    {
        $inspect = null;
        if ($this->inspectingTermId) {
            $inspect = [
                'term' => $reviews->term($this->inspectingTermId),
                'changes' => $reviews->pendingChanges($this->inspectingTermId),
                'before' => $reviews->lastApprovedTargets($this->inspectingTermId),
            ];
        }

        return view('livewire.review-queue', [
            'terms' => $reviews->pendingTerms(
                sheetId: $this->sheet === 'all' ? null : (int) $this->sheet,
                sinceDays: $this->age === 'all' ? null : (int) $this->age,
            ),
            'sheets' => \App\Models\Sheet::orderBy('name')->pluck('name', 'id'),
            'inspect' => $inspect,
        ]);
    }
}
