@extends('layouts.app')
@section('title', 'Mapped Terms by OMOP Domain')
@section('content')
<div class="card">
    <div style="display:flex; align-items:baseline; gap:1rem; flex-wrap:wrap;">
        <h1 style="color: var(--comet-gold); margin:0;">Mapped Terms by OMOP Domain</h1>
        @if ($vocabRelease)
            <span style="font-size:0.85rem; color: var(--comet-brown);">OMOP vocabulary: <b>{{ $vocabRelease }}</b></span>
        @else
            <span style="font-size:0.85rem; color:#b00020;">No vocabulary loaded</span>
        @endif
    </div>

    <p style="color:#555;">
        {{ number_format($resolved) }} of {{ number_format($totalMaps) }} maps resolve to a concept in the loaded vocabulary.
    </p>

    @if ($unresolved > 0)
        <div class="card" style="background:#fff6e0; border-color:#d0b060; margin:0.5rem 0 1rem;">
            <b style="color:#8a5a00;">{{ number_format($unresolved) }} map(s) are not counted below</b> —
            their target concept isn't in the currently loaded vocabulary
            @if ($vocabRelease)(<b>{{ $vocabRelease }}</b>)@endif,
            so their OMOP domain can't be determined. This is expected when only a partial or dev-fixture
            vocabulary is loaded; load a full Athena release
            (<code>comet:load-vocab</code>, see <code>docs/vocab-refresh.md</code>) for the complete domain distribution.
        </div>
    @endif

    @if ($domains->isEmpty())
        <p style="color:#b00020;">No maps resolve to a loaded concept yet — load a full OMOP vocabulary to populate domains.</p>
    @else
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem; max-width:500px;">
            <thead>
                <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                    <th style="padding:0.5rem;">OMOP Domain</th>
                    <th style="padding:0.5rem; text-align:right;">Maps</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($domains as $d)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.5rem;">{{ $d->domain_id }}</td>
                    <td style="padding:0.5rem; text-align:right;">{{ number_format($d->map_count) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
