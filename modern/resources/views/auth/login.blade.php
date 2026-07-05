@extends('layouts.app')
@section('title', 'COMET — Sign in')
@section('content')
<div class="card" style="max-width: 420px; margin: 4rem auto; text-align: center;">
    <h1 style="color: var(--comet-gold); font-style: italic;">COMET</h1>
    <p>Central Online Mapping and Export Tool</p>

    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif

    <p style="margin-top: 2rem;">
        <a href="{{ route('auth.redirect') }}"
           style="display:inline-block; background:#0078d4; color:#fff; padding:0.6rem 1.4rem; border-radius:4px; text-decoration:none; font-weight:600;">
            Sign in with Microsoft
        </a>
    </p>

    @if (config('services.comet.local_login'))
        <details style="margin-top: 2rem; text-align: left;">
            <summary style="cursor:pointer; color:#888;">Local sign-in (break-glass)</summary>
            <form method="POST" action="{{ route('auth.local') }}" style="margin-top:1rem; display:grid; gap:0.6rem;">
                @csrf
                <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required
                       style="padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
                <input type="password" name="password" placeholder="Password" required
                       style="padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
                <button type="submit" style="padding:0.5rem; background:var(--comet-brown); color:#fff; border:0; border-radius:4px; cursor:pointer;">
                    Sign in
                </button>
            </form>
        </details>
    @endif
</div>
@endsection
