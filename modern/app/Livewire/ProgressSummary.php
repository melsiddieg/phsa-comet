<?php

namespace App\Livewire;

use App\Services\SheetProgress;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Live mapping-progress widget for the welcome screen. Auto-refreshes via
 * wire:poll and on demand; all figures come from ground truth (SheetProgress),
 * so they track the DB rather than the drift-prone legacy counters.
 */
class ProgressSummary extends Component
{
    public string $updatedAt = '';

    public function refresh(): void
    {
        // Re-render pulls fresh numbers; timestamp shows liveness.
    }

    public function render(SheetProgress $progress): View
    {
        $rows = $progress->all();

        $overall = [
            'items' => $rows->sum('num_items'),
            'mapped' => $rows->sum('mapped'),
            'unmapped' => $rows->sum('unmapped'),
            'excluded' => $rows->sum('excluded'),
            'question' => $rows->sum('question'),
            'sdo' => $rows->sum('sdo'),
            'total_vol' => $rows->sum('total_vol'),
            'mapped_vol' => $rows->sum('mapped_vol'),
        ];

        $this->updatedAt = now()->format('H:i:s');

        return view('livewire.progress-summary', [
            'overall' => $overall,
            'rows' => $rows,
        ]);
    }
}
