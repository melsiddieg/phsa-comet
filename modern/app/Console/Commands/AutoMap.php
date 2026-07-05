<?php

namespace App\Console\Commands;

use App\Jobs\GenerateCandidates;
use App\Models\Sheet;
use Illuminate\Console\Command;

/**
 * Precompute ranked mapping candidates for every unmapped, unexcluded term in
 * a sheet (or all sheets), so the editor and mapping mode open instantly.
 * Candidates only — never writes a map (human-in-the-loop; no LLM).
 *
 *   php artisan comet:auto-map 15
 *   php artisan comet:auto-map --all
 */
class AutoMap extends Command
{
    protected $signature = 'comet:auto-map {sheet? : sheet id} {--all : every sheet} {--sync : run inline instead of queueing}';

    protected $description = 'Generate ranked mapping candidates for unmapped terms';

    public function handle(): int
    {
        $sheetIds = $this->option('all')
            ? Sheet::pluck('id')->all()
            : [(int) $this->argument('sheet')];

        if (! $this->option('all') && ! $this->argument('sheet')) {
            $this->error('Provide a sheet id or --all.');

            return self::FAILURE;
        }

        foreach ($sheetIds as $id) {
            if ($this->option('sync')) {
                (new GenerateCandidates($id))->handle(app(\App\Services\ConceptSearch::class));
                $this->info("Candidates generated for sheet $id.");
            } else {
                GenerateCandidates::dispatch($id);
                $this->info("Queued candidate generation for sheet $id.");
            }
        }

        return self::SUCCESS;
    }
}
