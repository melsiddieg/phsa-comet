<div>
    <h1 style="color: var(--comet-gold);">User Administration</h1>
    @if ($message)<div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ $message }}</div>@endif

    <div class="card" style="padding:0; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                    <th style="padding:0.5rem;">Name</th><th style="padding:0.5rem;">Email</th>
                    <th style="padding:0.5rem;">Source</th><th style="padding:0.5rem;">Enabled</th>
                    <th style="padding:0.5rem;">Mapper</th><th style="padding:0.5rem;">Importer</th>
                    <th style="padding:0.5rem;">Reviewer</th><th style="padding:0.5rem;">Admin</th>
                    <th style="padding:0.5rem;">Last login</th>
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
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p style="color:#888; font-size:0.85rem; margin-top:0.5rem;">New Entra sign-ins appear here automatically with no roles until granted.</p>
</div>
