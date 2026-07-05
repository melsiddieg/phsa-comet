<?php

namespace App\Livewire;

use App\Models\Release;
use App\Services\ReleaseService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

/** Admin: create and list vocab-tagged map releases. */
class ReleasesAdmin extends Component
{
    #[Validate('required|string|max:100|unique:releases,name')]
    public string $name = '';

    #[Validate('nullable|string|max:500')]
    public string $notes = '';

    public string $message = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function createRelease(ReleaseService $releases): void
    {
        $this->authorize('admin');
        $this->validate();

        $release = $releases->create($this->name, $this->notes ?: null, auth()->user()->name);
        $this->message = "Release “{$release->name}” created with {$release->map_count} maps"
            .($release->vocab_release ? " (vocabulary {$release->vocab_release})" : '').'.';
        $this->reset('name', 'notes');
    }

    public function render(): View
    {
        return view('livewire.releases-admin', [
            'releases' => Release::orderByDesc('id')->get(),
        ]);
    }
}
