<div>
    <h1 style="color: var(--comet-gold);">Vocabulary Impact Report</h1>
    <p style="color:var(--comet-brown);">Current vocabulary release: <b>{{ $vocabRelease ?? 'none loaded' }}</b></p>

    @if ($message)<div class="card" style="background:#eefaee; color:#1b5e20; margin-bottom:1rem;">{{ $message }}</div>@endif

    <p><b>{{ number_format($staleCount) }}</b> map(s) point at concepts that are deprecated, non-standard, or missing in the current vocabulary.</p>

    @if ($staleMaps->isEmpty())
        <div class="card"><p style="color:#2e7d32;">All maps point at standard, valid concepts. Nothing to do. 🎉</p></div>
    @else
        <div class="card" style="padding:0; overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="background:#202080; color:#fff; text-align:left;">
                        <th style="padding:0.4rem;">Map</th><th style="padding:0.4rem;">Source</th>
                        <th style="padding:0.4rem;">Current target</th><th style="padding:0.4rem;">Problem</th>
                        <th style="padding:0.4rem;">Suggested replacement</th><th style="padding:0.4rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($staleMaps as $m)
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:0.4rem;">#{{ $m->map_id }}</td>
                        <td style="padding:0.4rem;">{{ $m->source_code }} — {{ $m->source_description }}</td>
                        <td style="padding:0.4rem;">{{ $m->target_concept_name }} ({{ $m->target_concept_id }}, {{ $m->target_vocabulary_id }})</td>
                        <td style="padding:0.4rem; color:#b00020;">{{ $m->problem }}</td>
                        <td style="padding:0.4rem;">
                            @forelse ($m->replacements as $r)
                                {{ $r->concept_name }} ({{ $r->concept_code }}, {{ $r->vocabulary_id }})<br>
                            @empty
                                <i>none found</i>
                            @endforelse
                        </td>
                        <td style="padding:0.4rem; white-space:nowrap;">
                            @foreach ($m->replacements as $r)
                                <button type="button" wire:click="remap({{ $m->map_id }}, {{ $r->concept_id }})"
                                        wire:confirm="Remap map #{{ $m->map_id }} to {{ $r->concept_name }}?">
                                    Remap to {{ $r->concept_code }}
                                </button>
                            @endforeach
                            @if ($m->source_term_id)
                                <button type="button" wire:click="sendToQuestion({{ $m->source_term_id }})">Send to Question</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
