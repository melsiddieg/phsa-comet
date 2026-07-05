@extends('layouts.app')
@section('title', 'Team Dashboard')
@section('content')
<div class="card" style="margin-bottom:1.25rem;">
    <h1 style="color: var(--comet-gold);">Team Dashboard</h1>

    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin:0.75rem 0;">
        @foreach ([
            ['Review backlog', $backlog->total ?? 0, '#1a237e'],
            ['Backlog > 7 days', $backlog->over7 ?? 0, '#b06000'],
            ['Returned to mappers', $pipeline['returned'], '#8a5a00'],
            ['Questions', $pipeline['questions'], '#8a5a00'],
            ['SDO: to send', $pipeline['sdo_send'], '#b06000'],
            ['SDO: submitted', $pipeline['sdo_pending'], '#666'],
            ['Claims in flight', $pipeline['claims_in_flight'], '#2e7d32'],
        ] as [$label, $value, $color])
            <div style="flex:1; min-width:130px; text-align:center; padding:0.6rem; background:#fafafa; border-radius:8px;">
                <div style="font-size:1.6rem; font-weight:700; color:{{ $color }};">{{ number_format($value) }}</div>
                <div style="font-size:0.78rem; color:#555;">{{ $label }}</div>
            </div>
        @endforeach
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Mapper throughput (map Add/Update)</h2>
    @if ($throughput->isEmpty())
        <p style="color:#888;">No mapping activity in the last 30 days.</p>
    @else
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem; max-width:600px;">
            <thead>
                <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                    <th style="padding:0.5rem;">Mapper</th>
                    <th style="padding:0.5rem; text-align:right;">Last 7 days</th>
                    <th style="padding:0.5rem; text-align:right;">Last 30 days</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($throughput as $row)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.5rem;">{{ $row->username }}</td>
                    <td style="padding:0.5rem; text-align:right;">{{ number_format($row->last7) }}</td>
                    <td style="padding:0.5rem; text-align:right;">{{ number_format($row->last30) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
