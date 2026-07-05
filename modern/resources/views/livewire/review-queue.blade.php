<div>
    <h1 style="color: var(--comet-gold);">Review &amp; Approve Changed Maps</h1>

    @if ($message)
        <div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ $message }}</div>
    @endif

    <div class="card" style="margin-bottom:1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
        <label>Sheet<br>
            <select wire:model.live="sheet">
                <option value="all">All sheets</option>
                @foreach ($sheets as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label>Age<br>
            <select wire:model.live="age">
                <option value="all">Any age</option>
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
            </select>
        </label>
        @if (count($selected))
            <button type="button" wire:click="bulkApprove" wire:confirm="Approve {{ count($selected) }} selected term(s)?">
                Approve {{ count($selected) }} selected
            </button>
        @endif
        <span wire:loading style="color:#888;">…</span>
    </div>

    @if ($terms->isEmpty())
        <div class="card"><p style="color:#2e7d32;">No changes are pending review. 🎉</p></div>
    @else
        <div class="card" style="padding:0; overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="background:#202080; color:#fff; text-align:left;">
                        <th style="padding:0.5rem;"><input type="checkbox" disabled title="Select rows below"></th>
                        <th style="padding:0.5rem;">Sheet</th>
                        <th style="padding:0.5rem;">Term</th>
                        <th style="padding:0.5rem;">Pending changes</th>
                        <th style="padding:0.5rem;">Last change</th>
                        <th style="padding:0.5rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($terms as $t)
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:0.5rem;"><input type="checkbox" wire:model.live="selected" value="{{ $t->id }}"></td>
                        <td style="padding:0.5rem;">{{ $t->sheet_name }}</td>
                        <td style="padding:0.5rem;">#{{ $t->id }}</td>
                        <td style="padding:0.5rem;">{{ $t->pending_count }}</td>
                        <td style="padding:0.5rem;">{{ $t->last_change }}</td>
                        <td style="padding:0.5rem; white-space:nowrap;">
                            <button type="button" wire:click="inspect({{ $t->id }})">Inspect</button>
                            <button type="button" wire:click="approve({{ $t->id }})"
                                    wire:confirm="Approve all pending changes for term #{{ $t->id }}?">Approve</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:1rem;">{{ $terms->links() }}</div>
    @endif

    {{-- Inspect overlay: before/after --}}
    @if ($inspect)
        <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:50; overflow-y:auto;" wire:click.self="closeInspect">
            <div style="background:var(--comet-cream); max-width:860px; margin:2.5rem auto; border-radius:10px; padding:1.5rem; position:relative;">
                <button type="button" wire:click="closeInspect" style="position:absolute; top:0.8rem; right:1rem; font-size:1.3rem; background:none; border:none; cursor:pointer;">✕</button>
                <h2 style="margin-top:0; color:var(--comet-gold);">
                    {{ $inspect['term']->codes->firstWhere('spot', 1)?->description ?? 'Term '.$inspect['term']->id }}
                    <span style="font-size:0.8rem; color:#777;">#{{ $inspect['term']->id }} · {{ $inspect['term']->sheet->name }}</span>
                </h2>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="card">
                        <b>Before (last approved)</b>
                        @php $before = $inspect['before']; @endphp
                        @if ($before->isEmpty())
                            <p style="color:#888;">No prior approved snapshot.</p>
                        @else
                            <ul>@foreach ($before as $cid)<li>{{ $cid }}</li>@endforeach</ul>
                        @endif
                    </div>
                    <div class="card">
                        <b>After (current)</b>
                        @forelse ($inspect['term']->maps as $m)
                            <div>{{ $m->target_concept_name }} <span style="color:#888;">({{ $m->targetConcept?->concept_code ?? $m->target_concept_id }})</span></div>
                        @empty
                            <p style="color:#c07000;">No current map.</p>
                        @endforelse
                    </div>
                </div>

                <h3>Pending changes</h3>
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                    <tr style="text-align:left; color:#777;"><th>When</th><th>Action</th><th>Target</th><th>By</th></tr>
                    @foreach ($inspect['changes'] as $c)
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:0.3rem;">{{ $c->created_at }}</td>
                            <td style="padding:0.3rem;">{{ $c->action }}</td>
                            <td style="padding:0.3rem;">{{ $c->target_concept_name }} {{ $c->before_target_concept_id ? '(was '.$c->before_target_concept_id.')' : '' }}</td>
                            <td style="padding:0.3rem;">{{ $c->username }}</td>
                        </tr>
                    @endforeach
                </table>

                <div style="margin-top:1rem; border-top:1px solid #e4d9c6; padding-top:1rem;">
                    <textarea wire:model="returnComment" rows="2" style="width:100%;"
                              placeholder="Comment for the mapper (required to request changes)…"></textarea>
                    <div style="display:flex; justify-content:space-between; margin-top:0.5rem;">
                        <button type="button" wire:click="requestChanges({{ $inspect['term']->id }})">↩ Request changes</button>
                        <button type="button" wire:click="approve({{ $inspect['term']->id }})"
                                wire:confirm="Approve all pending changes for this term?">Approve all</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
