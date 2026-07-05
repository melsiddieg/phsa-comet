<div wire:poll.5s>
    <h1 style="color: var(--comet-gold);">Import MappingReport</h1>
    <p style="color:#555;">Tab-delimited Cerner extracts are read from a read-only mount; a queued job upserts terms, tracks MR drift, and records an import run.</p>

    @if ($message)
        <div class="card" style="background:#eef4ff; color:#1a237e; margin-bottom:1rem;">{{ $message }}</div>
    @endif

    <div class="card" style="padding:0; overflow-x:auto; margin-bottom:1.5rem;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background: var(--comet-brown); color:#F8EBD5; text-align:left;">
                    <th style="padding:0.5rem;">Cerner Area</th>
                    <th style="padding:0.5rem;">Last import</th>
                    <th style="padding:0.5rem;">Data file</th>
                    <th style="padding:0.5rem;">Import</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($sheets as $sheet)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.5rem;">{{ $sheet->name }}</td>
                    <td style="padding:0.5rem;">{{ $sheet->last_import_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td style="padding:0.5rem;">
                        @if (! $sheet->file_exists)
                            <span style="color:#999;">no file</span>
                        @elseif ($sheet->file_newer)
                            <span style="color:#b06000;" title="Newer than last import">{{ $sheet->file_mtime }} ⚠</span>
                        @else
                            <span style="color:#2e7d32;">{{ $sheet->file_mtime }}</span>
                        @endif
                    </td>
                    <td style="padding:0.5rem;">
                        @if ($sheet->file_exists)
                            <button type="button" wire:click="startImport({{ $sheet->id }})"
                                    wire:confirm="Import '{{ $sheet->name }}' now?">Import</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <h2>Recent import runs</h2>
    <div class="card" style="padding:0; overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
            <thead>
                <tr style="background:#202080; color:#fff; text-align:left;">
                    <th style="padding:0.4rem;">Sheet</th><th style="padding:0.4rem;">File</th>
                    <th style="padding:0.4rem;">Status</th><th style="padding:0.4rem;">Total</th>
                    <th style="padding:0.4rem;">New</th><th style="padding:0.4rem;">Updated</th>
                    <th style="padding:0.4rem;">Absent</th><th style="padding:0.4rem;">By</th>
                    <th style="padding:0.4rem;">Started</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($runs as $run)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:0.4rem;">{{ $run->sheet->name ?? $run->sheet_id }}</td>
                    <td style="padding:0.4rem;">{{ $run->filename }}</td>
                    <td style="padding:0.4rem;">
                        @php $c = ['completed'=>'#2e7d32','failed'=>'#b00020','running'=>'#b06000','pending'=>'#888'][$run->status] ?? '#888'; @endphp
                        <b style="color:{{ $c }};">{{ $run->status }}</b>
                        @if ($run->error)<div style="color:#b00020; font-size:0.75rem;">{{ Str::limit($run->error, 120) }}</div>@endif
                    </td>
                    <td style="padding:0.4rem;">{{ number_format($run->rows_total) }}</td>
                    <td style="padding:0.4rem;">{{ number_format($run->rows_new) }}</td>
                    <td style="padding:0.4rem;">{{ number_format($run->rows_updated) }}</td>
                    <td style="padding:0.4rem;">{{ number_format($run->rows_absent) }}</td>
                    <td style="padding:0.4rem;">{{ $run->started_by }}</td>
                    <td style="padding:0.4rem;">{{ $run->started_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="padding:1rem; text-align:center; color:#888;">No imports yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
