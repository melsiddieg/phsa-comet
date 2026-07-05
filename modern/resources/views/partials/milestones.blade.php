@php
    $milestones = config('comet.milestones', []);
    $done = collect($milestones)->where('status', 'done')->count();
    $total = count($milestones);
    $pct = $total > 0 ? round($done / $total * 100) : 0;
    $badge = [
        'done' => ['✅', '#2e7d32'],
        'in_progress' => ['🔄', '#b06000'],
        'planned' => ['⬜', '#999'],
    ];
@endphp

<div style="display:flex; align-items:baseline; justify-content:space-between; flex-wrap:wrap; gap:0.5rem;">
    <h2 style="margin:0; color:var(--comet-brown);">Modernization status</h2>
    <span style="font-size:0.85rem; color:#555;">{{ $done }} / {{ $total }} milestones complete ({{ $pct }}%)</span>
</div>

<div style="height:14px; background:#e0e0e0; border:1px solid #b0b0b0; border-radius:4px; overflow:hidden; margin:0.6rem 0 0.9rem;">
    <div style="width:{{ $pct }}%; height:14px; background:#4caf50;"></div>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:0.4rem;">
    @foreach ($milestones as $m)
        @php [$icon, $color] = $badge[$m['status']] ?? ['⬜', '#999']; @endphp
        <div style="display:flex; gap:0.5rem; align-items:flex-start; padding:0.35rem 0.5rem; background:#fafafa; border-radius:6px;">
            <span>{{ $icon }}</span>
            <span style="font-size:0.85rem;">
                <b style="color:{{ $color }};">{{ $m['id'] }}</b>
                <span style="color:#444;"> — {{ $m['title'] }}</span>
            </span>
        </div>
    @endforeach
</div>
