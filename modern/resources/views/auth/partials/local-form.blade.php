<form method="POST" action="{{ route('auth.local') }}" style="margin-top:1rem; display:grid; gap:0.6rem; text-align:left;">
    @csrf
    <input type="email" name="email" placeholder="Email" value="{{ old('email') }}" required autocomplete="username"
           style="padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
    <input type="password" name="password" placeholder="Password" required autocomplete="current-password"
           style="padding:0.5rem; border:1px solid #ccc; border-radius:4px;">
    <button type="submit" style="padding:0.5rem; background:var(--comet-brown); color:#fff; border:0; border-radius:4px; cursor:pointer;">
        Sign in
    </button>
</form>
