<div wire:poll.30s>
    @php
        $items = (int) $overall['items'];
        $mapped = (int) $overall['mapped'];
        $pct = $items > 0 ? round($mapped / $items * 100) : 0;
        $vol = (int) $overall['total_vol'];
        $mappedVol = (int) $overall['mapped_vol'];
        $volPct = $vol > 0 ? round($mappedVol / $vol * 100) : 0;
        $unmapped = (int) $overall['unmapped'];
        $excluded = (int) $overall['excluded'];
        $mapW = $items > 0 ? round($mapped / $items * 100) : 0;
        $openW = $items > 0 ? round(($unmapped + (int) $overall['question']) / $items * 100) : 0;
        $exclW = max(0, 100 - $mapW - $openW);
    @endphp

    <div style="display:flex; align-items:baseline; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
        <h2 style="margin:0; color:var(--comet-brown);">Mapping progress</h2>
        <span style="font-size:0.75rem; color:#999;">
            updated {{ $updatedAt }}
            <button type="button" wire:click="refresh" title="Refresh now"
                    style="border:none; background:none; cursor:pointer; color:#000080;">↻</button>
            <span wire:loading style="color:#bbb;">…</span>
        </span>
    </div>

    {{-- Headline numbers --}}
    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin:0.75rem 0;">
        <div style="flex:1; min-width:150px; text-align:center; padding:0.6rem; background:#eefaee; border-radius:8px;">
            <div style="font-size:1.8rem; font-weight:700; color:#2e7d32;">{{ $pct }}%</div>
            <div style="font-size:0.8rem; color:#555;">{{ number_format($mapped) }} / {{ number_format($items) }} terms mapped</div>
        </div>
        <div style="flex:1; min-width:150px; text-align:center; padding:0.6rem; background:#eef4ff; border-radius:8px;">
            <div style="font-size:1.8rem; font-weight:700; color:#1a237e;">{{ $volPct }}%</div>
            <div style="font-size:0.8rem; color:#555;">by record volume ({{ number_format($mappedVol) }} / {{ number_format($vol) }})</div>
        </div>
        <div style="flex:1; min-width:150px; text-align:center; padding:0.6rem; background:#fff6e0; border-radius:8px;">
            <div style="font-size:1.8rem; font-weight:700; color:#b06000;">{{ number_format($unmapped) }}</div>
            <div style="font-size:0.8rem; color:#555;">terms still open{{ (int) $overall['question'] ? ' · '.number_format($overall['question']).' ?' : '' }}</div>
        </div>
        <div style="flex:1; min-width:150px; text-align:center; padding:0.6rem; background:#f0f0f0; border-radius:8px;">
            <div style="font-size:1.8rem; font-weight:700; color:#666;">{{ number_format($excluded) }}</div>
            <div style="font-size:0.8rem; color:#555;">excluded (out of scope)</div>
        </div>
    </div>

    {{-- Overall bar --}}
    <div style="display:flex; height:20px; background:#e0e0e0; border:1px solid #b0b0b0; border-radius:4px; overflow:hidden; margin-bottom:1rem;">
        <div title="Mapped: {{ number_format($mapped) }}" style="width:{{ $mapW }}%; background:#4caf50;"></div>
        <div title="Open: {{ number_format($unmapped + (int) $overall['question']) }}" style="width:{{ $openW }}%; background:#ffb74d;"></div>
        <div title="Excluded: {{ number_format($excluded) }}" style="width:{{ $exclW }}%; background:#b0b0b0;"></div>
    </div>

    {{-- Per-sheet mini progress --}}
    <details>
        <summary style="cursor:pointer; color:#000080; font-size:0.9rem;">Per-sheet breakdown ({{ $rows->count() }} sheets)</summary>
        <table style="width:100%; border-collapse:collapse; font-size:0.82rem; margin-top:0.5rem;">
            @foreach ($rows->sortByDesc('num_items') as $r)
                @php
                    $n = (int) $r->num_items; $m = (int) $r->mapped;
                    $p = $n > 0 ? round($m / $n * 100) : 0;
                @endphp
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:0.25rem 0.4rem;"><a href="{{ route('sheets.show', $r->id) }}" style="color:#000080;">{{ $r->name }}</a></td>
                    <td style="padding:0.25rem 0.4rem; width:160px;">
                        <div style="height:10px; background:#e0e0e0; border-radius:3px; overflow:hidden;">
                            <div style="width:{{ $p }}%; height:10px; background:#4caf50;"></div>
                        </div>
                    </td>
                    <td style="padding:0.25rem 0.4rem; text-align:right; white-space:nowrap;">{{ number_format($m) }}/{{ number_format($n) }} ({{ $p }}%)</td>
                </tr>
            @endforeach
        </table>
    </details>
</div>
