<?php

namespace App\Livewire;

use App\Jobs\ImportMappingReport;
use App\Models\ImportRun;
use App\Models\Sheet;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Import MappingReport UI (importer role). Lists every sheet with its matching
 * extract file (mounted read-only at config('comet.mr_data_path')), flags when
 * a file is newer than the last import, and dispatches a queued import.
 */
class ImportManager extends Component
{
    public string $message = '';

    public function mount(): void
    {
        $this->authorize('import');
    }

    public function startImport(int $sheetId): void
    {
        $this->authorize('import');

        $sheet = Sheet::findOrFail($sheetId);
        $path = $this->filePath($sheet->name);

        if (! is_readable($path)) {
            $this->message = "No data file found for '{$sheet->name}'.";

            return;
        }

        ImportMappingReport::dispatch($sheet->id, $path, auth()->user()->name);
        $this->message = "Import queued for '{$sheet->name}'. Progress appears below as it runs.";
    }

    private function filePath(string $sheetName): string
    {
        return rtrim(config('comet.mr_data_path'), '/')."/{$sheetName}.txt";
    }

    public function render(): View
    {
        $sheets = Sheet::orderBy('name')->get()->map(function (Sheet $sheet) {
            $path = $this->filePath($sheet->name);
            $exists = is_readable($path);

            return (object) [
                'id' => $sheet->id,
                'name' => $sheet->name,
                'last_import_at' => $sheet->last_import_at,
                'file_exists' => $exists,
                'file_mtime' => $exists ? date('Y-m-d H:i', filemtime($path)) : null,
                'file_newer' => $exists && $sheet->last_import_at && filemtime($path) > $sheet->last_import_at->timestamp,
            ];
        });

        return view('livewire.import-manager', [
            'sheets' => $sheets,
            'runs' => ImportRun::with('sheet')->latest('id')->limit(20)->get(),
        ]);
    }
}
