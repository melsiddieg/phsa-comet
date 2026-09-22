<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Admin: manage user roles and enabled state, and local accounts (stop-gap
 * until Entra SSO is available). Every change is audited.
 */
class UserAdmin extends Component
{
    public string $message = '';

    // New local account form
    public string $newName = '';
    public string $newEmail = '';
    public bool $newMapper = false;
    public bool $newImporter = false;
    public bool $newReviewer = false;
    public bool $newAdmin = false;

    /**
     * A temporary password shown to the admin exactly once, after creating an
     * account or resetting a password. Cleared on the next action.
     *
     * @var array{name: string, email: string, password: string}|null
     */
    public ?array $issued = null;

    private const ROLE_FIELDS = ['is_mapper', 'is_importer', 'is_reviewer', 'is_portal_admin', 'enabled'];

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function toggle(int $userId, string $field): void
    {
        $this->authorize('admin');
        $this->issued = null;

        if (! in_array($field, self::ROLE_FIELDS, true)) {
            abort(400);
        }

        $user = User::findOrFail($userId);

        // Don't let an admin strip their own admin/enabled and lock themselves out.
        if ($user->id === auth()->id() && in_array($field, ['is_portal_admin', 'enabled'], true) && $user->{$field}) {
            $this->message = 'You cannot remove your own admin access or disable yourself.';

            return;
        }

        $old = $user->{$field};
        $new = ! $old;
        $user->update([$field => $new]);

        // A disabled user is signed out everywhere immediately.
        if ($field === 'enabled' && ! $new) {
            $user->revokeSessions();
        }

        $this->audit($user, $field, $old ? '1' : '0', $new ? '1' : '0');
        $this->message = "Updated {$field} for {$user->name}.";
    }

    public function createLocalUser(): void
    {
        $this->authorize('admin');
        $this->issued = null;

        $data = $this->validate([
            'newName' => ['required', 'string', 'max:255'],
            'newEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], [
            'newEmail.unique' => 'A user with this email already exists.',
        ]);

        $password = TemporaryPassword::generate();

        $user = User::create([
            'name' => $data['newName'],
            'email' => strtolower($data['newEmail']),
            'password' => $password,            // hashed by the model cast
            'auth_source' => 'local',
            'enabled' => true,
            'must_change_password' => true,
            'is_mapper' => $this->newMapper,
            'is_importer' => $this->newImporter,
            'is_reviewer' => $this->newReviewer,
            'is_portal_admin' => $this->newAdmin,
        ]);

        $this->audit($user, 'account_created');

        $this->issued = ['name' => $user->name, 'email' => $user->email, 'password' => $password];
        $this->message = "Created local account for {$user->name}.";
        $this->reset('newName', 'newEmail', 'newMapper', 'newImporter', 'newReviewer', 'newAdmin');
    }

    public function resetPassword(int $userId): void
    {
        $this->authorize('admin');
        $this->issued = null;

        $user = User::findOrFail($userId);

        if (! $user->isLocal()) {
            $this->message = 'Only local accounts have a password here. Entra accounts are managed in Microsoft.';

            return;
        }
        if ($user->id === auth()->id()) {
            $this->message = 'To change your own password, use "Change password" at the top of the page.';

            return;
        }

        $password = TemporaryPassword::generate();
        $user->update(['password' => $password, 'must_change_password' => true]);
        $user->revokeSessions();   // the old password stops working everywhere, now

        $this->audit($user, 'password_reset');

        $this->issued = ['name' => $user->name, 'email' => $user->email, 'password' => $password];
        $this->message = "Password reset for {$user->name}. They have been signed out.";
    }

    public function dismissIssued(): void
    {
        $this->issued = null;
    }

    private function audit(User $user, string $field, ?string $old = null, ?string $new = null): void
    {
        DB::table('user_audits')->insert([
            'user_id' => $user->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'changed_by' => auth()->user()->name,
            'created_at' => now(),
        ]);
    }

    public function render(): View
    {
        return view('livewire.user-admin', [
            'users' => User::orderBy('name')->get(),
            'localLoginEnabled' => (bool) config('services.comet.local_login'),
        ]);
    }
}
