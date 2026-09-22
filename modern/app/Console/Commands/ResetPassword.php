<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give a local account a new temporary password from the server command line.
 * Use it when nobody who can sign in as an admin knows their password, e.g.
 * the break-glass admin password was forgotten.
 *
 *   php artisan comet:reset-password admin@comet.local
 *
 * The user is signed out everywhere and must choose their own password at
 * next sign-in, exactly like a reset from the Users screen.
 */
class ResetPassword extends Command
{
    protected $signature = 'comet:reset-password {email : email of the LOCAL account to reset}';

    protected $description = 'Set a new temporary password for a local account (emergency admin recovery)';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }
        if (! $user->isLocal()) {
            $this->error("{$email} signs in with Microsoft (Entra). Its password is managed in Microsoft, not here.");

            return self::FAILURE;
        }

        $password = TemporaryPassword::generate();
        $user->update(['password' => $password, 'must_change_password' => true]);
        $user->revokeSessions();

        DB::table('user_audits')->insert([
            'user_id' => $user->id,
            'field' => 'password_reset',
            'old_value' => null,
            'new_value' => null,
            'changed_by' => 'server console',
            'created_at' => now(),
        ]);

        $this->info("Temporary password for {$user->name} ({$email}):");
        $this->line('');
        $this->line("    {$password}");
        $this->line('');
        $this->line('They must choose their own password at next sign-in.');
        if (! $user->enabled) {
            $this->warn('This account is DISABLED. Enable it on the Users screen before it can sign in.');
        }
        if (! config('services.comet.local_login')) {
            $this->warn('Local sign-in is OFF (AUTH_LOCAL_LOGIN=0). Set it to 1 before this password can be used.');
        }

        return self::SUCCESS;
    }
}
