<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the break-glass local administrator.
     *
     * Creates the account only if it does not exist. Running the seeder again
     * never touches an existing admin, so it cannot reset a password that was
     * already changed.
     *
     * The initial password comes from BREAK_GLASS_PASSWORD, or falls back to
     * "change-me-now". With the fallback, the admin must choose a new password
     * at first sign-in. The account only works when AUTH_LOCAL_LOGIN=1.
     */
    public function run(): void
    {
        $password = env('BREAK_GLASS_PASSWORD') ?: null;

        User::firstOrCreate(
            ['email' => 'admin@comet.local'],
            [
                'name' => 'Break-glass Admin',
                'password' => $password ?? 'change-me-now',   // hashed by the model cast
                'must_change_password' => $password === null,
                'auth_source' => 'local',
                'enabled' => true,
                'is_mapper' => true,
                'is_importer' => true,
                'is_reviewer' => true,
                'is_portal_admin' => true,
            ]
        );
    }
}
