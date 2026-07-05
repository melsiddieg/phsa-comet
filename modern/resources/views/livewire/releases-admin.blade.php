<div>
    <h1 style="color: var(--comet-gold);">Map Releases</h1>

    @if ($message)<div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ $message }}</div>@endif

    <div class="card" style="margin-bottom:1.5rem;">
        <b>Create a release</b> (freezes all current maps)
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:flex-start; margin-top:0.5rem;">
            <div>
                <input type="text" wire:model="name" placeholder="Release name" style="width:220px;">
                @error('name') <div class="error" style="font-size:0.8rem;">{{ $message }}</div> @enderror
            </div>
            <input type="text" wire:model="notes" placeholder="Notes (optional)" style="width:320px;">
            <button type="button" wire:click="createRelease" wire:confirm="Freeze all current maps into a new release?">Create release</button>
        </div>
    </div>

    <p><a href="{{ route('export.stcm') }}" style="color:#000080;">⬇ Download current live maps as STCM CSV</a></p>

    <div class="card" style="padding:0; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#202080; color:#fff; text-align:left;">
                    <th style="padding:0.5rem;">ID</th><th style="padding:0.5rem;">Name</th>
                    <th style="padding:0.5rem;">Vocabulary</th><th style="padding:0.5rem;">Maps</th>
                    <th style="padding:0.5rem;">By</th><th style="padding:0.5rem;">Created</th>
                    <th style="padding:0.5rem;">Notes</th><th style="padding:0.5rem;">Export</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($releases as $r)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.5rem;">{{ $r->id }}</td>
                    <td style="padding:0.5rem;">{{ $r->name }}</td>
                    <td style="padding:0.5rem;">{{ $r->vocab_release }}</td>
                    <td style="padding:0.5rem;">{{ number_format($r->map_count) }}</td>
                    <td style="padding:0.5rem;">{{ $r->created_by }}</td>
                    <td style="padding:0.5rem;">{{ $r->created_at }}</td>
                    <td style="padding:0.5rem;">{{ $r->notes }}</td>
                    <td style="padding:0.5rem;"><a href="{{ route('export.stcm.release', $r->id) }}" style="color:#000080;">STCM CSV</a></td>
                </tr>
            @empty
                <tr><td colspan="8" style="padding:1rem; text-align:center; color:#888;">No releases yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
