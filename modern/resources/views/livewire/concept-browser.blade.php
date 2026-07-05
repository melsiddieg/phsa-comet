<div>
    <h1 style="color: var(--comet-gold);">Vocabulary Browser</h1>
    <p style="color:#555;">Search the whole OMOP vocabulary (names + synonyms, accent-insensitive). Click a concept for details, hierarchy, and relationships.</p>

    <div class="card" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1rem;">
        <label>Search<br>
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="e.g. sitting blood pressure / hypertension essentielle" style="width:320px;">
        </label>
        <label>Vocabulary<br><input type="text" wire:model.live.debounce.400ms="vocab" placeholder="all" style="width:120px;"></label>
        <label>Domain<br><input type="text" wire:model.live.debounce.400ms="domain" placeholder="all" style="width:120px;"></label>
        <label><input type="checkbox" wire:model.live="standardOnly"> Standard only</label>
        <label><input type="checkbox" wire:model.live="validOnly"> Valid only</label>
        <span wire:loading style="color:#888;">Searching…</span>
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#202080; color:#fff; text-align:left;">
                    <th style="padding:0.4rem;">Concept</th><th style="padding:0.4rem;">Code</th>
                    <th style="padding:0.4rem;">Domain</th><th style="padding:0.4rem;">Vocab</th>
                    <th style="padding:0.4rem;">Score</th><th style="padding:0.4rem;">Via</th>
                    <th style="padding:0.4rem;">Team uses</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($results as $r)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.4rem;">
                        <a href="#" wire:click.prevent="showDetail({{ $r->concept_id }})" style="color:#000080;">{{ $r->concept_name }}</a>
                        @if ($r->standard_concept !== 'S' || $r->invalid_reason)<span style="color:#b00020;">⚠</span>@endif
                    </td>
                    <td style="padding:0.4rem;">{{ $r->concept_code }}</td>
                    <td style="padding:0.4rem;">{{ $r->domain_id }}</td>
                    <td style="padding:0.4rem;">{{ $r->vocabulary_id }}</td>
                    <td style="padding:0.4rem;">{{ $r->score }}</td>
                    <td style="padding:0.4rem; color:#888;">{{ $r->matched_on === 'synonym' ? 'syn: '.$r->synonym_name : $r->matched_on }}</td>
                    <td style="padding:0.4rem;">{{ $r->team_uses ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:1rem; text-align:center; color:#888;">{{ $q === '' ? 'Type to search.' : 'No matches.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($detail)
        @include('partials.concept-detail', ['detail' => $detail, 'closeAction' => 'closeDetail'])
    @endif
</div>
