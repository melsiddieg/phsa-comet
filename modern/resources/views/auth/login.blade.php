@extends('layouts.app')
@section('title', 'COMET — Sign in')
@section('content')
@php
    // Show the Microsoft button only when Entra is configured; otherwise it
    // sends users to a Microsoft error page (AADSTS900144: missing client_id).
    $entra = filled(config('services.azure.client_id'));
    $local = (bool) config('services.comet.local_login');
@endphp
<div class="card" style="max-width: 420px; margin: 4rem auto; text-align: center;">
    <h1 style="color: var(--comet-gold); font-style: italic;">COMET</h1>
    <p>Central Online Mapping and Export Tool</p>

    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif

    @if ($entra)
        <p style="margin-top: 2rem;">
            <a href="{{ route('auth.redirect') }}"
               style="display:inline-block; background:#0078d4; color:#fff; padding:0.6rem 1.4rem; border-radius:4px; text-decoration:none; font-weight:600;">
                Sign in with Microsoft
            </a>
        </p>
    @endif

    @if ($local)
        @if ($entra)
            <details style="margin-top: 2rem; text-align: left;">
                <summary style="cursor:pointer; color:#888;">Sign in with a local account</summary>
                @include('auth.partials.local-form')
            </details>
        @else
            <div style="margin-top: 1.5rem;">
                @include('auth.partials.local-form')
            </div>
        @endif
    @endif

    @if (! $entra && ! $local)
        <p class="error" style="margin-top:2rem;">
            Sign-in is not configured. An administrator must set up Entra ID
            (<code>ENTRA_*</code>) or enable local accounts (<code>AUTH_LOCAL_LOGIN=1</code>).
        </p>
    @endif
</div>
@endsection
