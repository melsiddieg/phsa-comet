<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the break-glass local administrator.
     *
     * Password comes from BREAK_GLASS_PASSWORD (falls back to a dev default —
     * change it before anything production-bound). The account only works when
     * AUTH_LOCAL_LOGIN=1.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@comet.local'],
            [
                'name' => 'Break-glass Admin',
                'password' => Hash::make(env('BREAK_GLASS_PASSWORD', 'change-me-now')),
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
