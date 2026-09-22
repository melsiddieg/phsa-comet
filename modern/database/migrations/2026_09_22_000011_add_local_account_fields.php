<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Local accounts for the team (stop-gap until Entra SSO is available).
 *
 * must_change_password  set when an admin creates an account or resets its
 *                       password; the user must pick their own on next login.
 * password_changed_at   when the user last set their own password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
        });

        // Any local account still on the seeder's default password must change
        // it at next login. This covers servers seeded before this migration.
        DB::table('users')
            ->where('auth_source', 'local')
            ->whereNotNull('password')
            ->get(['id', 'password'])
            ->filter(fn ($u) => Hash::check('change-me-now', $u->password))
            ->each(fn ($u) => DB::table('users')->where('id', $u->id)->update(['must_change_password' => true]));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
