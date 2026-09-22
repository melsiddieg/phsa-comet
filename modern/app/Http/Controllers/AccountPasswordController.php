<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/** A local user changes their own password. Entra users have no password here. */
class AccountPasswordController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless($request->user()->isLocal(), 404);

        return view('account.password', ['forced' => $request->user()->must_change_password]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isLocal(), 404);

        $request->validate([
            'current_password' => ['required', 'current_password'],
            // max:72 because bcrypt only uses the first 72 bytes.
            'password' => ['required', 'confirmed', 'different:current_password', 'max:72', Password::min(12)],
        ], [
            'current_password.current_password' => 'Your current password is not correct.',
            'password.different' => 'The new password must be different from the current one.',
        ]);

        $user->update([
            'password' => $request->input('password'),   // hashed by the model cast
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        DB::table('user_audits')->insert([
            'user_id' => $user->id,
            'field' => 'password_changed',
            'old_value' => null,
            'new_value' => null,
            'changed_by' => $user->name,
            'created_at' => now(),
        ]);

        // Sign out every other browser that was using the old password.
        $user->revokeSessions($request->session()->getId());
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Your password has been changed.');
    }
}
