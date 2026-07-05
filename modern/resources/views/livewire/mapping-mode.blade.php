<div x-data
     x-on:keydown.window="
        if (['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            if ($event.key === 'Escape') document.activeElement.blur();
            return;
        }
        if ($event.key >= '1' && $event.key <= '9') { $event.preventDefault(); $wire.pick(parseInt($event.key)); }
        else if ($event.key === 'e') { $event.preventDefault(); $wire.setStatus('exclude'); }
        else if ($event.key === 'q') { $event.preventDefault(); $wire.setStatus('question'); }
        else if ($event.key === 's') { $event.preventDefault(); $wire.setStatus('sdo'); }
        else if ($event.key === 'k') { $event.preventDefault(); $wire.skip(); }
        else if ($event.key === '/') { $event.preventDefault(); document.getElementById('mm-search').focus(); }
     ">

    <div style="display:flex; align-items:baseline; gap:1rem; flex-wrap:wrap; margin-bottom:0.75rem;">
        <a href="{{ route('sheets.show', $sheet->id) }}" style="color:#000080;">&larr; {{ $sheet->name }} grid</a>
        <h1 style="color: var(--comet-gold); margin:0;">Mapping mode</h1>
        <span style="color:#777; font-size:0.85rem;">{{ number_format($remaining) }} unmapped left · {{ $sessionMapped }} mapped this session</span>
    </div>

    <div style="font-size:0.75rem; color:#888; margin-bottom:0.5rem;">
        Keys: <b>1–9</b> map candidate · <b>e</b> exclude · <b>q</b> question · <b>s</b> SDO · <b>k</b> skip · <b>/</b> search
    </div>

    @if ($flash)
        <div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:0.75rem; padding:0.5rem 0.8rem;">{{ $flash }}</div>
    @endif

    @if (! $term)
        <div class="card"><p style="color:#2e7d32; font-size:1.1rem;">🎉 No unmapped terms left in this sheet.</p></div>
    @else
        <div class="card" style="margin-bottom:1rem;">
            <div style="font-size:1.3rem; font-weight:600;">{{ $term->codes->firstWhere('spot', 1)?->description ?? 'Term '.$term->id }}</div>
            <div style="color:#777; font-size:0.85rem;">
                @foreach ($term->codes as $c){{ $c->code }}@if(!$loop->last) · @endif @endforeach
                · usage {{ $term->total_count !== null ? number_format($term->total_count) : '—' }}
            </div>
            <div style="margin-top:0.5rem; display:flex; gap:0.5rem; align-items:center;">
                <input id="mm-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Override search (/)" style="width:320px;">
                <select wire:model.live="equivalence" title="Equivalence applied to the map">
                    <option value="">equivalence…</option>
                    <option value="EQUAL">EQUAL</option>
                    <option value="EQUIVALENT">EQUIVALENT</option>
                    <option value="WIDER">WIDER</option>
                    <option value="NARROWER">NARROWER</option>
                    <option value="INEXACT">INEXACT</option>
                </select>
                <button type="button" wire:click="skip">Skip (k)</button>
            </div>
        </div>

        <div class="card" style="padding:0;">
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:#202080; color:#fff; text-align:left;">
                        <th style="padding:0.4rem; width:2rem;">#</th>
                        <th style="padding:0.4rem;">Candidate concept</th>
                        <th style="padding:0.4rem;">Code</th><th style="padding:0.4rem;">Domain</th>
                        <th style="padding:0.4rem;">Vocab</th><th style="padding:0.4rem;">Score</th><th style="padding:0.4rem;">Via</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($candidates as $i => $c)
                    <tr style="border-bottom:1px solid #eee; cursor:pointer;" wire:click="mapCandidate({{ $c->concept_id }})">
                        <td style="padding:0.4rem;"><kbd style="background:#eee; border-radius:3px; padding:0 0.35rem;">{{ $i + 1 }}</kbd></td>
                        <td style="padding:0.4rem; font-weight:600;">{{ $c->concept_name }}</td>
                        <td style="padding:0.4rem;">{{ $c->concept_code }}</td>
                        <td style="padding:0.4rem;">{{ $c->domain_id }}</td>
                        <td style="padding:0.4rem;">{{ $c->vocabulary_id }}</td>
                        <td style="padding:0.4rem;">{{ is_numeric($c->score) ? round($c->score, 3) : $c->score }}</td>
                        <td style="padding:0.4rem; color:#888;">{{ $c->matched_on }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:1rem; text-align:center; color:#888;">No candidates — use the search box, or e/q/s to set a status.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
