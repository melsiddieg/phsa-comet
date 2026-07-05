@extends('layouts.app')
@section('title', 'Mapped Terms by OMOP Domain')
@section('content')
<div class="card">
    <h1 style="color: var(--comet-gold);">Mapped Terms by OMOP Domain</h1>
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
