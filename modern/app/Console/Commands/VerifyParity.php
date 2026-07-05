<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Parallel-run cutover check: compares the modern map set against the legacy
 * MySQL phsa_all_maps as (source_code, target_concept_id) pairs, and reports
 * any differences. A clean run (0 only-in-legacy, 0 only-in-modern) is the
 * green light to cut over.
 *
 *   php artisan comet:verify-parity
 */
class VerifyParity extends Command
{
    protected $signature = 'comet:verify-parity {--show=20 : how many diffs to print}';

    protected $description = 'Diff modern maps against the legacy database (cutover parity)';

    public function handle(): int
    {
        try {
            $legacy = $this->legacyPairs();
        } catch (\Throwable $e) {
            $this->error('Cannot reach legacy DB: '.$e->getMessage());

            return self::FAILURE;
        }
        $modern = $this->modernPairs();

        $this->line(sprintf('Legacy map pairs: %d   Modern map pairs: %d', $legacy->count(), $modern->count()));

        $onlyLegacy = $legacy->diff($modern);
        $onlyModern = $modern->diff($legacy);

        $show = (int) $this->option('show');
        $this->report('Only in LEGACY (missing from modern)', $onlyLegacy, $show);
        $this->report('Only in MODERN (extra vs legacy)', $onlyModern, $show);

        if ($onlyLegacy->isEmpty() && $onlyModern->isEmpty()) {
            $this->info('✔ Parity confirmed — modern and legacy map sets are identical. Safe to cut over.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('✗ %d only-in-legacy, %d only-in-modern.', $onlyLegacy->count(), $onlyModern->count()));

        return self::FAILURE;
    }

    /** "source_code|target_concept_id" pairs from legacy phsa_all_maps. */
    private function legacyPairs()
    {
        return DB::connection('legacy_mysql')
            ->table('phsa_all_maps')
            ->selectRaw('concat(source_code_1, "|", target_concept_id) as pair')
            ->pluck('pair');
    }

    private function modernPairs()
    {
        return DB::table('maps as m')
            ->leftJoin('source_term_codes as sc', fn ($j) => $j->on('sc.source_term_id', '=', 'm.source_term_id')->where('sc.spot', 1))
            ->selectRaw("concat(coalesce(sc.code, m.source_code), '|', m.target_concept_id) as pair")
            ->pluck('pair');
    }

    private function report(string $label, $diffs, int $show): void
    {
        $this->newLine();
        $this->line("$label: ".$diffs->count());
        foreach ($diffs->take($show) as $d) {
            $this->line("  $d");
        }
    }
}
