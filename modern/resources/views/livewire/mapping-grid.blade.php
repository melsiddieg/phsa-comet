<div>
    @php
        // Row background mirroring legacy get_tr_bgcolor(): drift status + mapped state.
        $rowColor = fn (?object $map, string $mrStatus): string =>
            $mrStatus === 'Absent in latest MR' ? '#fdecec' : ($map ? '#eefaee' : '#fff7e6');
    @endphp

    <div style="display:flex; align-items:baseline; gap:1rem; flex-wrap:wrap; margin-bottom:0.75rem;">
        <a href="{{ route('sheets.index') }}" style="color:#000080;">&larr; Cerner Areas</a>
        <h1 style="color: var(--comet-gold); margin:0;">{{ $sheet->name }}</h1>
        <span style="color:#777; font-size:0.85rem;">{{ number_format($terms->total()) }} terms</span>
    </div>

    {{-- Filters --}}
    <div class="card" style="margin-bottom:1rem; display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end;">
        <label>Term status<br>
            <select wire:model.live="status">
                <option value="all">No filter</option>
                <option value="i">Included only</option>
                <option value="e">Excluded only</option>
                <option value="q">Questions only</option>
                <option value="s">To submit to SDO</option>
                <option value="p">Submitted to SDO</option>
            </select>
        </label>
        <label>Mapped<br>
            <select wire:model.live="mapped">
                <option value="all">No filter</option>
                <option value="m">Mapped</option>
                <option value="a">Auto-map only</option>
                <option value="n">Not mapped</option>
            </select>
        </label>
        <label>Vocabulary<br>
            <select wire:model.live="vocab">
                <option value="all">No filter</option>
                @foreach ($vocabOptions as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach
            </select>
        </label>
        <label>Domain<br>
            <select wire:model.live="domain">
                <option value="all">No filter</option>
                @foreach ($domainOptions as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach
            </select>
        </label>
        <label>Search description<br>
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="e.g. abortion">
        </label>
        <button type="button" wire:click="clearFilters" style="padding:0.4rem 0.8rem;">Clear</button>
        <span wire:loading style="color:#888;">Loading…</span>
    </div>

    <div class="card" style="overflow-x:auto; padding:0;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#202080; color:#fff; text-align:left;">
                    <th style="padding:0.4rem;">Status</th>
                    @foreach ($spots as $spot)
                        <th style="padding:0.4rem;">{{ $spot->code_label }}</th>
                        <th style="padding:0.4rem;">{{ $spot->desc_label }}</th>
                    @endforeach
                    <th style="padding:0.4rem;">Count</th>
                    <th style="padding:0.4rem;">Map Src</th>
                    <th style="padding:0.4rem; color:#ffd0d0;">Map Target</th>
                    <th style="padding:0.4rem; color:#ffd0d0;">Domain</th>
                    <th style="padding:0.4rem; color:#ffd0d0;">Vocabulary</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($terms as $term)
                @php
                    $termCodes = $codes->get($term->id) ?? collect();
                    $termMaps = $mapsByTerm->get($term->id) ?? collect();
                    $bg = $rowColor($termMaps->first(), (string) $term->mr_status);
                @endphp
                <tr style="background:{{ $bg }}; border-bottom:1px solid #e8e8e8;">
                    <td style="padding:0.4rem; white-space:nowrap;">
                        @switch($term->exclude_status)
                            @case('Out of Scope - Exclude') <span title="Excluded">🚫</span> @break
                            @case('Question - Pending') <span title="Question">❓</span> @break
                            @case('SDO Submission - Send') <span title="To SDO">📤</span> @break
                            @case('SDO Submitted - Pending') <span title="Submitted">⏳</span> @break
                            @default <span title="Included">✔️</span>
                        @endswitch
                    </td>
                    @foreach ($spots as $spot)
                        @php $c = $termCodes->firstWhere('spot', $spot->spot); @endphp
                        <td style="padding:0.4rem; white-space:nowrap;">{{ $c->code ?? '' }}</td>
                        <td style="padding:0.4rem;">{{ $c->description ?? '' }}</td>
                    @endforeach
                    <td style="padding:0.4rem; text-align:right;">{{ $term->total_count !== null ? number_format($term->total_count) : '' }}</td>
                    <td style="padding:0.4rem;">{{ $term->map_source }}</td>
                    <td style="padding:0.4rem;">
                        @forelse ($termMaps as $m)
                            <div>
                                {{ $m->target_concept_name }}
                                <span style="color:#888;">({{ $m->concept_code ?? $m->target_concept_id }})</span>
                                @if (($m->concept_id ?? false) && ($m->standard_concept !== 'S' || $m->invalid_reason))
                                    <span style="color:#b00020;" title="Not a standard/valid target">⚠</span>
                                @endif
                            </div>
                        @empty
                            <span style="color:#c07000;">— unmapped —</span>
                        @endforelse
                    </td>
                    <td style="padding:0.4rem;">{{ $termMaps->pluck('domain_id')->filter()->unique()->implode(', ') }}</td>
                    <td style="padding:0.4rem;">{{ $termMaps->pluck('target_vocabulary_id')->filter()->unique()->implode(', ') }}</td>
                </tr>
            @empty
                <tr><td colspan="20" style="padding:1rem; text-align:center; color:#888;">No terms match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1rem;">
        {{ $terms->links() }}
    </div>
</div>
