@extends('layouts.app')
@section('title', 'COMET — Home')
@section('content')
<div class="card">
    <h1 style="color: var(--comet-gold);"><i>Welcome to COMET</i></h1>
    <p>Signed in as <b>{{ auth()->user()->name }}</b> ({{ auth()->user()->email }})</p>

    <ul style="line-height: 2;">
        <li>Source Terms by Cerner Area <em>(M2)</em></li>
        <li>Mapped Terms by OMOP Domain <em>(M2)</em></li>
        @can('review')
            <li>Review Mapping Changes <em>(M5)</em> · Vocabulary Impact Report <em>(M7)</em></li>
        @endcan
        <li>Exports <em>(M6)</em></li>
        @can('import')
            <li>Import MappingReport <em>(M4)</em></li>
        @endcan
        @can('admin')
            <li>Map Releases · User Administration <em>(M6)</em></li>
        @endcan
    </ul>

    @if (! auth()->user()->is_mapper && ! auth()->user()->is_reviewer && ! auth()->user()->is_importer && ! auth()->user()->is_portal_admin)
        <p class="error">Your account has no roles yet — ask a portal administrator to grant access.</p>
    @endif
</div>
@endsection
