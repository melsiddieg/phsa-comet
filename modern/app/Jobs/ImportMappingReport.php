<?php

namespace App\Jobs;

use App\Models\Sheet;
use App\Services\MappingReportImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a MappingReport import off the request cycle. The importer itself wraps
 * the work in a transaction and records progress/failure on the import_runs
 * row, so a crashed job leaves a "failed" run rather than partial data.
 */
class ImportMappingReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public int $sheetId,
        public string $path,
        public string $startedBy,
    ) {}

    public function handle(MappingReportImporter $importer): void
    {
        $importer->import(Sheet::findOrFail($this->sheetId), $this->path, $this->startedBy);
    }
}
