<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class AuthController extends Controller
{
    /** Redirect to Entra ID (authorization-code flow; Socialite handles state). */
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('azure')->redirect();
    }

    /** Entra callback: validate, JIT-provision, establish the session. */
    public function callback(Request $request): RedirectResponse
    {
        $azureUser = Socialite::driver('azure')->user();

        $user = User::firstOrCreate(
            ['entra_oid' => $azureUser->getId()],
            [
                // JIT provisioning: account exists but carries NO roles until a
                // portal admin grants them - access stays with the data team.
                'name' => $azureUser->getName() ?: $azureUser->getNickname() ?: $azureUser->getEmail(),
                'email' => $azureUser->getEmail(),
                'auth_source' => 'entra',
                'enabled' => true,
            ]
        );

        if (! $user->enabled) {
            return redirect()->route('login')->withErrors(['auth' => 'This account is disabled.']);
        }

        // Keep profile fresh + login audit
        $user->update([
            'name' => $azureUser->getName() ?: $user->name,
            'email' => $azureUser->getEmail() ?: $user->email,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * Local login (break-glass admin and team stop-gap accounts).
     * Only when AUTH_LOCAL_LOGIN=1 and auth_source='local'.
     */
    public function loginLocal(Request $request): RedirectResponse
    {
        abort_unless(config('services.comet.local_login'), 404);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // 5 attempts per minute per email + IP, so passwords cannot be guessed quickly.
        $throttleKey = 'local-login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors(['auth' => "Too many sign-in attempts. Try again in {$seconds} seconds."])
                ->onlyInput('email');
        }

        $user = User::where('email', $credentials['email'])
            ->where('auth_source', 'local')
            ->where('enabled', true)
            ->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['auth' => 'Invalid credentials.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);
        Auth::login($user);
        $request->session()->regenerate();

        if ($user->must_change_password) {
            return redirect()->route('account.password');
        }

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $wasEntra = $request->user()?->auth_source === 'entra';

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($wasEntra && ($tenant = config('services.azure.tenant'))) {
            // Front-channel logout clears the Microsoft session too
            $postLogout = urlencode(route('login'));

            return redirect()->away(
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/logout?post_logout_redirect_uri={$postLogout}"
            );
        }

        return redirect()->route('login');
    }
}
