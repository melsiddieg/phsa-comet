<div>
    <h1 style="color: var(--comet-gold);">User Administration</h1>

    @unless ($localLoginEnabled)
        <div class="card" style="background:#fff4e5; border:1px solid #f0c27b; margin-bottom:1rem;">
            Local sign-in is turned off (<code>AUTH_LOCAL_LOGIN=0</code>). Local accounts below exist but cannot sign in.
        </div>
    @endunless

    @if ($message)<div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ $message }}</div>@endif

    @if ($issued)
        <div class="card" style="background:#fffbe6; border:2px solid #C28119; margin-bottom:1rem;">
            <strong>Temporary password for {{ $issued['name'] }}</strong> ({{ $issued['email'] }})
            <div style="font-family:monospace; font-size:1.4rem; margin:0.6rem 0; letter-spacing:0.05em; user-select:all;">{{ $issued['password'] }}</div>
            <p style="margin:0.3rem 0;">
                Give this to the user by phone or in person, not in the same email as the sign-in address.
                <strong>It is shown only once.</strong> They must choose their own password when they first sign in.
            </p>
            <button type="button" wire:click="dismissIssued" style="margin-top:0.4rem;">I have passed it on — hide it</button>
        </div>
    @endif

    <div class="card" style="padding:0; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                    <th style="padding:0.5rem;">Name</th><th style="padding:0.5rem;">Email</th>
                    <th style="padding:0.5rem;">Source</th><th style="padding:0.5rem;">Enabled</th>
                    <th style="padding:0.5rem;">Mapper</th><th style="padding:0.5rem;">Importer</th>
                    <th style="padding:0.5rem;">Reviewer</th><th style="padding:0.5rem;">Admin</th>
                    <th style="padding:0.5rem;">Last login</th><th style="padding:0.5rem;">Password</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($users as $u)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.5rem;">{{ $u->name }}</td>
                    <td style="padding:0.5rem;">{{ $u->email }}</td>
                    <td style="padding:0.5rem;">{{ $u->auth_source }}</td>
                    @foreach (['enabled', 'is_mapper', 'is_importer', 'is_reviewer', 'is_portal_admin'] as $field)
                        <td style="padding:0.5rem; text-align:center;">
                            <button type="button" wire:click="toggle({{ $u->id }}, '{{ $field }}')"
                                    title="Toggle {{ $field }}"
                                    style="border:none; background:none; cursor:pointer; font-size:1.1rem;">
                                {{ $u->{$field} ? '✅' : '⬜' }}
                            </button>
                        </td>
                    @endforeach
                    <td style="padding:0.5rem;">{{ $u->last_login_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td style="padding:0.5rem; white-space:nowrap;">
                        @if ($u->isLocal())
                            @if ($u->must_change_password)<span style="color:#b26a00;" title="Must choose a new password at next sign-in">pending</span>@endif
                            @if ($u->id !== auth()->id())
                                <button type="button" wire:click="resetPassword({{ $u->id }})"
                                        wire:confirm="Reset the password for {{ $u->name }}? They will be signed out and get a new temporary password.">
                                    Reset
                                </button>
                            @endif
                        @else
                            <span style="color:#888;">via Microsoft</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p style="color:#888; font-size:0.85rem; margin-top:0.5rem;">New Entra sign-ins appear here automatically with no roles until granted.</p>

    <div class="card" style="margin-top:1.5rem; max-width:560px;">
        <h2 style="margin-top:0; font-size:1.1rem;">Add a local account</h2>
        <p style="color:#666; font-size:0.85rem; margin-top:0;">
            Stop-gap until Entra SSO is set up. COMET generates a temporary password; the user must replace it at first sign-in.
        </p>
        <form wire:submit="createLocalUser" style="display:grid; gap:0.6rem;">
            <label>Full name
                <input type="text" wire:model="newName" required maxlength="255"
                       style="display:block; width:100%; padding:0.45rem; border:1px solid #ccc; border-radius:4px;">
            </label>
            @error('newName')<span class="error">{{ $message }}</span>@enderror
            <label>Email (their sign-in name)
                <input type="email" wire:model="newEmail" required maxlength="255"
                       style="display:block; width:100%; padding:0.45rem; border:1px solid #ccc; border-radius:4px;">
            </label>
            @error('newEmail')<span class="error">{{ $message }}</span>@enderror
            <fieldset style="border:1px solid #ddd; border-radius:4px;">
                <legend>Roles</legend>
                <label><input type="checkbox" wire:model="newMapper"> Mapper</label>&nbsp;
                <label><input type="checkbox" wire:model="newImporter"> Importer</label>&nbsp;
                <label><input type="checkbox" wire:model="newReviewer"> Reviewer</label>&nbsp;
                <label><input type="checkbox" wire:model="newAdmin"> Admin</label>
            </fieldset>
            <button type="submit" style="padding:0.5rem; background:var(--comet-brown); color:#fff; border:0; border-radius:4px; cursor:pointer;">
                Create account
            </button>
        </form>
    </div>
</div>
