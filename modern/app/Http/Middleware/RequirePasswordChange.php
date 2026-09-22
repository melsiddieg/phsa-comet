<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A local user whose password was set by an admin (new account or reset)
 * must choose their own before using anything else.
 */
class RequirePasswordChange
{
    /** Routes the user can still reach while a change is required. */
    private const ALLOWED = ['account.password', 'account.password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isLocal() && $user->must_change_password
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return redirect()->route('account.password');
        }

        return $next($request);
    }
}
