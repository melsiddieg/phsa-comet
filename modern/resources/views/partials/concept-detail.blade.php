{{-- Expects $detail (array from ConceptDetail::for) and a Livewire close action name in $closeAction --}}
@php $c = $detail['concept']; @endphp
<div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:60; overflow-y:auto;" wire:click.self="{{ $closeAction }}">
    <div style="background:var(--comet-cream); max-width:720px; margin:2.5rem auto; border-radius:10px; padding:1.5rem; position:relative;">
        <button type="button" wire:click="{{ $closeAction }}" style="position:absolute; top:0.8rem; right:1rem; font-size:1.3rem; background:none; border:none; cursor:pointer;">✕</button>

        <h2 style="margin-top:0; color:var(--comet-gold);">
            {{ $c->concept_name }}
            @if ($c->standard_concept !== 'S' || $c->invalid_reason)<span style="color:#b00020; font-size:0.9rem;" title="Not a standard/valid target">⚠ non-standard</span>@endif
        </h2>
        <table style="font-size:0.85rem; margin-bottom:0.8rem;">
            <tr><td style="color:#777; padding:0.15rem 0.5rem;">Concept ID</td><td>{{ $c->concept_id }}</td>
                <td style="color:#777; padding:0.15rem 0.5rem;">Code</td><td>{{ $c->concept_code }}</td></tr>
            <tr><td style="color:#777; padding:0.15rem 0.5rem;">Vocabulary</td><td>{{ $c->vocabulary_id }}</td>
                <td style="color:#777; padding:0.15rem 0.5rem;">Domain</td><td>{{ $c->domain_id }}</td></tr>
            <tr><td style="color:#777; padding:0.15rem 0.5rem;">Class</td><td>{{ $c->concept_class_id }}</td>
                <td style="color:#777; padding:0.15rem 0.5rem;">Standard</td><td>{{ $c->standard_concept ?? '—' }} {{ $c->invalid_reason ? '(invalid: '.$c->invalid_reason.')' : '' }}</td></tr>
            <tr><td style="color:#777; padding:0.15rem 0.5rem;">Valid</td><td colspan="3">{{ $c->valid_start_date }} → {{ $c->valid_end_date }}</td></tr>
            <tr><td style="color:#777; padding:0.15rem 0.5rem;">Team uses</td><td colspan="3">{{ $detail['team_uses'] }} map(s) target this concept</td></tr>
        </table>

        @if ($detail['replacements']->isNotEmpty())
            <div class="card" style="background:#eef4ff; margin-bottom:0.8rem;">
                <b>Standard replacement(s):</b>
                @foreach ($detail['replacements'] as $r)
                    {{ $r->concept_name }} ({{ $r->concept_code }}, {{ $r->vocabulary_id }})@if(!$loop->last);@endif
                @endforeach
            </div>
        @endif

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div>
                <b>Parents</b>
                @forelse ($detail['parents'] as $p)
                    <div style="font-size:0.8rem;"><a href="#" wire:click.prevent="showDetail({{ $p->concept_id }})" style="color:#000080;">{{ $p->concept_name }}</a> <span style="color:#888;">({{ $p->vocabulary_id }})</span></div>
                @empty
                    <div style="color:#888; font-size:0.8rem;">none / hierarchy not loaded</div>
                @endforelse
            </div>
            <div>
                <b>Children</b>
                @forelse ($detail['children'] as $ch)
                    <div style="font-size:0.8rem;"><a href="#" wire:click.prevent="showDetail({{ $ch->concept_id }})" style="color:#000080;">{{ $ch->concept_name }}</a> <span style="color:#888;">({{ $ch->vocabulary_id }})</span></div>
                @empty
                    <div style="color:#888; font-size:0.8rem;">none / hierarchy not loaded</div>
                @endforelse
            </div>
        </div>

        <h3 style="margin-bottom:0.3rem;">Synonyms</h3>
        @forelse ($detail['synonyms'] as $s)
            <div style="font-size:0.82rem;">{{ $s->concept_synonym_name }} <span style="color:#aaa;">[{{ $s->language_concept_id == 4180186 ? 'EN' : ($s->language_concept_id == 4180536 ? 'FR' : $s->language_concept_id) }}]</span></div>
        @empty
            <div style="color:#888; font-size:0.8rem;">none</div>
        @endforelse

        <h3 style="margin-bottom:0.3rem;">Relationships</h3>
        @forelse ($detail['relationships'] as $r)
            <div style="font-size:0.82rem;"><i>{{ $r->relationship_id }}</i> → <a href="#" wire:click.prevent="showDetail({{ $r->concept_id }})" style="color:#000080;">{{ $r->concept_name }}</a> <span style="color:#888;">({{ $r->concept_code }}, {{ $r->vocabulary_id }})</span></div>
        @empty
            <div style="color:#888; font-size:0.8rem;">none</div>
        @endforelse
    </div>
</div>
