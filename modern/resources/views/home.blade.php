@extends('layouts.app')
@section('title', 'COMET — Home')
@section('content')
<div class="card" style="margin-bottom:1.25rem;">
    <livewire:progress-summary />
</div>

<div class="card" style="margin-bottom:1.25rem;">
    @include('partials.milestones')
</div>

<div class="card">
    <h1 style="color: var(--comet-gold);"><i>Welcome to COMET</i></h1>
    <p>Signed in as <b>{{ auth()->user()->name }}</b> ({{ auth()->user()->email }})</p>

    <ul style="line-height: 2.2; list-style: none; padding: 0; max-width: 460px;">
        <li><a href="{{ route('sheets.index') }}" style="color:#000080; font-weight:600;">Source Terms by Cerner Area</a></li>
        <li><a href="{{ route('domains.index') }}" style="color:#000080; font-weight:600;">Mapped Terms by OMOP Domain</a></li>
        @can('review')
            <li><a href="{{ route('review') }}" style="color:#000080; font-weight:600;">Review Mapping Changes</a> · <a href="{{ route('vocab.impact') }}" style="color:#000080;">Vocabulary Impact Report</a></li>
        @endcan
        <li><a href="{{ route('export.stcm') }}" style="color:#000080; font-weight:600;">Export Maps (STCM)</a> · <a href="{{ route('export.exclusions') }}" style="color:#000080;">Exclusions</a> · <a href="{{ route('export.sdo') }}" style="color:#000080;">SDO Submissions</a> · <a href="{{ route('export.usagi') }}" style="color:#000080;">Usagi</a></li>
        @can('import')
            <li><a href="{{ route('import') }}" style="color:#000080; font-weight:600;">Import MappingReport</a></li>
        @endcan
        @can('admin')
            <li><a href="{{ route('admin.releases') }}" style="color:#000080; font-weight:600;">Map Releases</a> · <a href="{{ route('admin.users') }}" style="color:#000080;">User Administration</a></li>
        @endcan
    </ul>

    @if (! auth()->user()->is_mapper && ! auth()->user()->is_reviewer && ! auth()->user()->is_importer && ! auth()->user()->is_portal_admin)
        <p class="error">Your account has no roles yet — ask a portal administrator to grant access.</p>
    @endif
</div>
@endsection
