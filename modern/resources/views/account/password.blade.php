@extends('layouts.app')
@section('title', 'COMET — Change password')
@section('content')
<div class="card" style="max-width: 460px; margin: 3rem auto;">
    <h1 style="color: var(--comet-gold); margin-top:0;">Change password</h1>

    @if ($forced)
        <p style="background:#fff4e5; border:1px solid #f0c27b; padding:0.6rem; border-radius:4px;">
            Your password was set by an administrator. Choose your own password before continuing.
        </p>
    @endif

    @if ($errors->any())
        <ul class="error" style="padding-left:1.2rem;">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('account.password.update') }}" style="display:grid; gap:0.7rem;">
        @csrf
        @method('PUT')
        <label>Current password
            <input type="password" name="current_password" required autocomplete="current-password"
                   style="display:block; width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
        </label>
        <label>New password
            <input type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password"
                   style="display:block; width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
        </label>
        <label>Confirm new password
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   style="display:block; width:100%; padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
        </label>
        <p style="color:#666; font-size:0.85rem; margin:0;">At least 12 characters. A short sentence works well.</p>
        <button type="submit" style="padding:0.55rem; background:var(--comet-brown); color:#fff; border:0; border-radius:4px; cursor:pointer;">
            Save new password
        </button>
    </form>
</div>
@endsection
