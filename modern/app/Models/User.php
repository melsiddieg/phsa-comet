<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'entra_oid',
        'auth_source',
        'enabled',
        'is_mapper',
        'is_importer',
        'is_reviewer',
        'is_portal_admin',
        'last_login_at',
        'last_login_ip',
        'must_change_password',
        'password_changed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'enabled' => 'boolean',
            'is_mapper' => 'boolean',
            'is_importer' => 'boolean',
            'is_reviewer' => 'boolean',
            'is_portal_admin' => 'boolean',
        ];
    }

    public function isLocal(): bool
    {
        return $this->auth_source === 'local';
    }

    /**
     * Sign the user out everywhere by deleting their stored sessions.
     * Pass the current session id to keep that one. Only has an effect with
     * SESSION_DRIVER=database, which production uses.
     */
    public function revokeSessions(?string $exceptSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
