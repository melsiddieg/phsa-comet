@extends('layouts.app')
@section('title', 'Source Terms by Cerner Area')
@section('content')

<div class="card">
    <div style="display:flex; align-items:baseline; gap:1rem; flex-wrap:wrap;">
        <h1 style="color: var(--comet-gold); margin:0;">Source Terms by Cerner Area</h1>
        @if ($vocabRelease)
            <span style="font-size:0.85rem; color: var(--comet-brown);">OMOP vocabulary: <b>{{ $vocabRelease }}</b></span>
        @else
            <span style="font-size:0.85rem; color:#b00020;">No vocabulary loaded</span>
        @endif
    </div>

    <p style="color:#555;">
        {{ number_format($totals->mapped) }} of {{ number_format($totals->num_items) }} terms mapped
        · {{ number_format($totals->excluded) }} excluded
    </p>

    <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
        <thead>
            <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                <th style="padding:0.5rem;">Cerner Area</th>
                <th style="padding:0.5rem; width:240px;">Progress (by item)</th>
                <th style="padding:0.5rem; text-align:center;">Mapped / Items</th>
                <th style="padding:0.5rem;">Status</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $r)
            @php
                $num = (int) $r->num_items; $mapped = (int) $r->mapped; $unmapped = (int) $r->unmapped;
                $excluded = (int) $r->excluded; $question = (int) $r->question; $sdo = (int) $r->sdo;
                $pct = $num > 0 ? round($mapped / $num * 100) : 0;
                $mappedW = $num > 0 ? round($mapped / $num * 100) : 0;
                $openW = $num > 0 ? round(($unmapped + $question) / $num * 100) : 0;
                $exclW = max(0, 100 - $mappedW - $openW);
            @endphp
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:0.5rem;">
                    <a href="{{ route('sheets.show', $r->id) }}" style="color:#000080; font-weight:600;">{{ $r->name }}</a>
                </td>
                <td style="padding:0.5rem;">
                    <div style="display:flex; height:16px; background:#e0e0e0; border:1px solid #b0b0b0; border-radius:3px; overflow:hidden;">
                        <div title="Mapped: {{ $mapped }}" style="width:{{ $mappedW }}%; background:#4caf50;"></div>
                        <div title="Open: {{ $unmapped + $question }}" style="width:{{ $openW }}%; background:#ffb74d;"></div>
                        <div title="Excluded: {{ $excluded }}" style="width:{{ $exclW }}%; background:#b0b0b0;"></div>
                    </div>
                </td>
                <td style="padding:0.5rem; text-align:center;">{{ number_format($mapped) }} / {{ number_format($num) }}<br><b>{{ $pct }}%</b></td>
                <td style="padding:0.5rem; font-size:0.8rem;">
                    <span style="color:#2e7d32;">&#9632;</span> {{ number_format($mapped) }} mapped ·
                    <span style="color:#ef6c00;">&#9632;</span> {{ number_format($unmapped) }} open
                    @if ($question) · {{ $question }} ?@endif
                    @if ($sdo) · {{ $sdo }} SDO @endif
                    @if ($excluded) · <span style="color:#808080;">&#9632;</span> {{ number_format($excluded) }} excl @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
