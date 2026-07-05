<div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:50; overflow-y:auto;" wire:click.self="close">
    <div style="background:var(--comet-cream); max-width:1080px; margin:2rem auto; border-radius:10px; padding:1.5rem; position:relative;">
        <button type="button" wire:click="close" style="position:absolute; top:0.8rem; right:1rem; font-size:1.3rem; background:none; border:none; cursor:pointer;">✕</button>

        <h2 style="margin-top:0; color:var(--comet-gold);">
            {{ $term->codes->firstWhere('spot', 1)?->description ?? 'Term '.$term->id }}
            <span style="font-size:0.8rem; color:#777;">#{{ $term->id }} · {{ $term->sheet->name }}</span>
        </h2>

        @if ($message)
            <div style="padding:0.5rem 0.8rem; border-radius:6px; margin-bottom:0.8rem;
                        background:{{ $messageType === 'error' ? '#fdecec' : ($messageType === 'warn' ? '#fff6e0' : '#eefaee') }};
                        color:{{ $messageType === 'error' ? '#b00020' : ($messageType === 'warn' ? '#8a5a00' : '#1b5e20') }};">
                {{ $message }}
            </div>
        @endif

        @if (count($replacements))
            <div style="padding:0.5rem 0.8rem; border-radius:6px; margin-bottom:0.8rem; background:#eef4ff; color:#1a237e;">
                Standard replacement{{ count($replacements) > 1 ? 's' : '' }}:
                @foreach ($replacements as $r)
                    <button type="button" wire:click="addMap('{{ $r['concept_code'] }}', '{{ $r['vocabulary_id'] }}')"
                            style="margin:0.15rem; padding:0.2rem 0.5rem; cursor:pointer;">
                        {{ $r['concept_name'] }} ({{ $r['concept_code'] }}, {{ $r['vocabulary_id'] }})
                    </button>
                @endforeach
            </div>
        @endif

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
            {{-- Left: term details + maps --}}
            <div class="card">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    @foreach ($term->sheet->sourceColumns as $spot)
                        @php $c = $term->codes->firstWhere('spot', $spot->spot); @endphp
                        <tr><td style="color:#777; padding:0.2rem 0.4rem;">{{ $spot->code_label }}</td><td>{{ $c->code ?? '' }}</td></tr>
                        <tr><td style="color:#777; padding:0.2rem 0.4rem;">{{ $spot->desc_label }}</td><td>{{ $c->description ?? '' }}</td></tr>
                    @endforeach
                    @foreach ($attributes as $a)
                        <tr><td style="color:#777; padding:0.2rem 0.4rem;">{{ $a->name }}</td><td>{{ $a->value }}</td></tr>
                    @endforeach
                    <tr><td style="color:#777; padding:0.2rem 0.4rem;">Count</td><td>{{ $term->total_count !== null ? number_format($term->total_count) : '—' }}</td></tr>
                    <tr><td style="color:#777; padding:0.2rem 0.4rem;">Map source</td><td>{{ $term->map_source ?? '—' }}</td></tr>
                    <tr><td style="color:#777; padding:0.2rem 0.4rem;">MR status</td><td>{{ $term->mr_status ?? '—' }}</td></tr>
                </table>

                <h3 style="margin-bottom:0.3rem;">Maps</h3>
                @forelse ($term->maps as $map)
                    <div style="border:1px solid #ddd; border-radius:6px; padding:0.5rem; margin-bottom:0.5rem; background:#fff;">
                        <b>{{ $map->target_concept_name }}</b>
                        <span style="color:#888;">({{ $map->targetConcept?->concept_code ?? $map->target_concept_id }}, {{ $map->target_vocabulary_id }})</span>
                        @if ($map->equivalence)<span style="font-size:0.7rem; background:#eef; padding:0 0.3rem; border-radius:3px;">{{ $map->equivalence }}</span>@endif
                        @can('map')
                            <div style="margin-top:0.4rem; display:flex; gap:0.4rem; flex-wrap:wrap;">
                                <input type="text" id="upd-code-{{ $map->id }}" value="{{ $map->targetConcept?->concept_code }}" size="14">
                                <select id="upd-vocab-{{ $map->id }}">
                                    @foreach ($sheetVocabs as $v)
                                        <option value="{{ $v }}" @selected($v === $map->target_vocabulary_id)>{{ $v }}</option>
                                    @endforeach
                                </select>
                                <button type="button"
                                    onclick="Livewire.find('{{ $this->getId() }}').call('updateMap', {{ $map->id }}, document.getElementById('upd-code-{{ $map->id }}').value, document.getElementById('upd-vocab-{{ $map->id }}').value)">
                                    Update
                                </button>
                                <button type="button" wire:click="deleteMap({{ $map->id }})" wire:confirm="Delete this map?">Delete</button>
                            </div>
                        @endcan
                    </div>
                @empty
                    <p style="color:#c07000;">No map yet.</p>
                @endforelse

                @can('map')
                    <h3 style="margin-bottom:0.3rem;">Add map</h3>
                    <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
                        <input type="text" wire:model="newCode" placeholder="Concept code" size="16">
                        <select wire:model="newVocabulary">
                            @foreach ($sheetVocabs as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach
                        </select>
                        <select wire:model="newEquivalence" title="Mapping equivalence">
                            <option value="">equivalence…</option>
                            <option value="EQUAL">EQUAL</option>
                            <option value="EQUIVALENT">EQUIVALENT</option>
                            <option value="WIDER">WIDER</option>
                            <option value="NARROWER">NARROWER</option>
                            <option value="INEXACT">INEXACT</option>
                        </select>
                        <button type="button" wire:click="addMap">Add</button>
                    </div>
                @endcan

                {{-- Status --}}
                @can('map')
                    <h3 style="margin-bottom:0.3rem;">Status</h3>
                    @foreach ([
                        '' => '✔️ Included',
                        'Question - Pending' => '❓ Question — pending SME clarification',
                        'Out of Scope - Exclude' => '🚫 Excluded — out of scope',
                        'SDO Submission - Send' => '📤 SDO Submission — send',
                        'SDO Submitted - Pending' => '⏳ SDO Submitted — pending',
                    ] as $value => $label)
                        <label style="display:block; font-size:0.85rem;">
                            <input type="radio" wire:model="excludeStatus" value="{{ $value }}"> {{ $label }}
                        </label>
                    @endforeach
                    <textarea wire:model="commentText" rows="3" style="width:100%; margin-top:0.4rem;" placeholder="Comment"></textarea>
                    <div style="text-align:right;"><button type="button" wire:click="saveStatus">Save status</button></div>
                @endcan
            </div>

            {{-- Right: propagation + concept search --}}
            <div>
                @if ($elsewhere->isNotEmpty())
                    <div class="card" style="background:#fff6e0; border-color:#d0b060; margin-bottom:1rem;">
                        <b style="color:#875503;">This term is mapped elsewhere</b>
                        <table style="width:100%; font-size:0.8rem; border-collapse:collapse; margin-top:0.4rem;">
                            @foreach ($elsewhere as $ex)
                                @php $exValid = $ex->standard_concept === 'S' && $ex->invalid_reason === null; @endphp
                                <tr style="border-bottom:1px solid #e8dcc0;">
                                    <td style="padding:0.3rem;">{{ $ex->target_concept_name }} ({{ $ex->concept_code ?? $ex->target_concept_id }})</td>
                                    <td style="padding:0.3rem;">×{{ $ex->used_count }}</td>
                                    <td style="padding:0.3rem; white-space:nowrap;">
                                        @if ($exValid)
                                            @can('map')
                                                @if ($term->maps->isEmpty() && $ex->concept_code)
                                                    <button type="button" wire:click="addMap('{{ $ex->concept_code }}', '{{ $ex->target_vocabulary_id }}')">Use here</button>
                                                @endif
                                                @if ($twinCount > 0)
                                                    <button type="button" wire:click="propagate({{ $ex->target_concept_id }})"
                                                            wire:confirm="Apply this target to all {{ $twinCount }} identical unmapped terms in this sheet?">
                                                        Apply to {{ $twinCount }} unmapped
                                                    </button>
                                                @endif
                                            @endcan
                                        @else
                                            <i style="color:#b00020;">non-standard</i>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif

                <div class="card" style="background:#eef4ff; border-color:#a0b0d0;">
                    <b style="color:#202080;">Find target concept</b>
                    <div style="display:flex; gap:0.4rem; flex-wrap:wrap; margin:0.5rem 0;">
                        <input type="search" wire:model="searchQuery" wire:keydown.enter="runSearch" size="28">
                        <select wire:model="searchDomain">
                            <option value="all">All domains</option>
                            @foreach ($sheetDomains as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
                        </select>
                        <select wire:model="searchVocab">
                            <option value="sheet">Sheet vocabularies</option>
                            <option value="all">All vocabularies</option>
                        </select>
                        <label style="align-self:center;"><input type="checkbox" wire:model="searchStandardOnly"> Standard</label>
                        <label style="align-self:center;"><input type="checkbox" wire:model="searchValidOnly"> Valid</label>
                        <button type="button" wire:click="runSearch">Search</button>
                    </div>
                    <div style="max-height:300px; overflow-y:auto;">
                        @if ($searchResults->isEmpty())
                            <p style="color:#888; font-size:0.85rem;">No matches — try fewer words, All vocabularies, or unticking Standard.</p>
                        @else
                            <table style="width:100%; font-size:0.8rem; border-collapse:collapse; background:#fff;">
                                <tr style="background:#202080; color:#fff;">
                                    <th></th><th style="text-align:left; padding:0.3rem;">Concept</th><th>Code</th><th>Domain</th><th>Vocab</th><th>Score</th><th>Via</th>
                                </tr>
                                @foreach ($searchResults as $r)
                                    <tr style="border-bottom:1px solid #eee;">
                                        <td style="padding:0.2rem;">
                                            @can('map')<button type="button" wire:click="useConcept('{{ $r->concept_code }}', '{{ $r->vocabulary_id }}')">Use</button>@endcan
                                            <button type="button" wire:click="showDetail({{ $r->concept_id }})" title="Details">🔍</button>
                                        </td>
                                        <td style="padding:0.3rem;">{{ $r->concept_name }}
                                            @if ($r->standard_concept !== 'S' || $r->invalid_reason)<span style="color:#b00020;">⚠</span>@endif
                                        </td>
                                        <td style="padding:0.3rem;">{{ $r->concept_code }}</td>
                                        <td style="padding:0.3rem;">{{ $r->domain_id }}</td>
                                        <td style="padding:0.3rem;">{{ $r->vocabulary_id }}</td>
                                        <td style="padding:0.3rem;">{{ $r->score }}</td>
                                        <td style="padding:0.3rem; color:#888;">{{ $r->matched_on === 'synonym' ? 'syn: '.$r->synonym_name : $r->matched_on }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                </div>

                {{-- History --}}
                <div class="card" style="margin-top:1rem;">
                    <b>Change history</b>
                    @if ($history->isEmpty())
                        <p style="color:#888; font-size:0.85rem;">No recorded changes.</p>
                    @else
                        <table style="width:100%; font-size:0.75rem; border-collapse:collapse;">
                            <tr style="text-align:left; color:#777;"><th>When</th><th>Action</th><th>Target</th><th>By</th><th>Reviewed</th></tr>
                            @foreach ($history as $h)
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:0.2rem;">{{ $h->created_at }}</td>
                                    <td style="padding:0.2rem;">{{ $h->action }}</td>
                                    <td style="padding:0.2rem;">{{ $h->target_concept_name }} {{ $h->before_target_concept_id ? '(was '.$h->before_target_concept_id.')' : '' }}</td>
                                    <td style="padding:0.2rem;">{{ $h->username }}</td>
                                    <td style="padding:0.2rem;">{{ $h->approved_by ?? 'pending' }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($detail)
        @include('partials.concept-detail', ['detail' => $detail, 'closeAction' => 'closeDetail'])
    @endif
</div>
