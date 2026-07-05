<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** Admin: manage user roles and enabled state; every change audited. */
class UserAdmin extends Component
{
    public string $message = '';

    private const ROLE_FIELDS = ['is_mapper', 'is_importer', 'is_reviewer', 'is_portal_admin', 'enabled'];

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function toggle(int $userId, string $field): void
    {
        $this->authorize('admin');

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

        DB::table('user_audits')->insert([
            'user_id' => $user->id,
            'field' => $field,
            'old_value' => $old ? '1' : '0',
            'new_value' => $new ? '1' : '0',
            'changed_by' => auth()->user()->name,
            'created_at' => now(),
        ]);

        $this->message = "Updated {$field} for {$user->name}.";
    }

    public function render(): View
    {
        return view('livewire.user-admin', [
            'users' => User::orderBy('name')->get(),
        ]);
    }
}
