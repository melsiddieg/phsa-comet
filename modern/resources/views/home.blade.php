@extends('layouts.app')
@section('title', 'COMET — Home')
@section('content')
<div class="card" style="margin-bottom:1.25rem;">
    <livewire:progress-summary />
</div>

<div class="card">
    <h1 style="color: var(--comet-gold);"><i>Welcome to COMET</i></h1>
    <p>Signed in as <b>{{ auth()->user()->name }}</b> ({{ auth()->user()->email }})</p>

    <ul style="line-height: 2.2; list-style: none; padding: 0; max-width: 420px;">
        <li><a href="{{ route('sheets.index') }}" style="color:#000080; font-weight:600;">Source Terms by Cerner Area</a></li>
        <li><a href="{{ route('domains.index') }}" style="color:#000080; font-weight:600;">Mapped Terms by OMOP Domain</a></li>
        @can('review')
            <li style="color:#999;">Review Mapping Changes · Vocabulary Impact Report <em>(M5 / M7)</em></li>
        @endcan
        <li style="color:#999;">Exports <em>(M6)</em></li>
        @can('import')
            <li style="color:#999;">Import MappingReport <em>(M4)</em></li>
        @endcan
        @can('admin')
            <li style="color:#999;">Map Releases · User Administration <em>(M6)</em></li>
        @endcan
    </ul>

    @if (! auth()->user()->is_mapper && ! auth()->user()->is_reviewer && ! auth()->user()->is_importer && ! auth()->user()->is_portal_admin)
        <p class="error">Your account has no roles yet — ask a portal administrator to grant access.</p>
    @endif
</div>
@endsection
