<?php

namespace App\Console\Commands;

use App\Services\CustomIdAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed Canadian source→standard maps from a CIHI map refset.
 *
 * CIHI publishes SNOMED CT-CA → ICD-10-CA/CCI maps. For OMOP we want the
 * reverse: the Canadian code as a source concept "Maps to" the standard SNOMED
 * concept. This reads a normalized refset (snomed_code<TAB>canadian_code),
 * resolves the SNOMED code to a *standard* concept already loaded from Athena
 * and the Canadian code to its custom 2-billion id, and appends
 * CONCEPT_RELATIONSHIP_CUSTOM.csv "Maps to" rows. Pairs whose SNOMED target
 * isn't standard/loaded are written to a review file.
 *
 *   php artisan comet:convert-cihi-maps ICD10CA storage/cihi/refset.tsv storage/out
 */
class ConvertCihiMaps extends Command
{
    protected $signature = 'comet:convert-cihi-maps {canadian_vocab : e.g. ICD10CA|CCI} {refset : snomed_code<TAB>canadian_code TSV} {outdir}';

    protected $description = 'Convert a CIHI SNOMED CT-CA map refset into Canadian→standard Maps-to rows';

    public function handle(CustomIdAllocator $ids): int
    {
        $vocab = $this->argument('canadian_vocab');
        $refset = $this->argument('refset');
        if (! is_readable($refset)) {
            $this->error("Refset not readable: $refset");

            return self::FAILURE;
        }
        $outdir = rtrim($this->argument('outdir'), '/');
        @mkdir($outdir, 0775, true);

        // Append so it can follow a comet:convert-cihi run for the same outdir.
        // Write the header only when the file is new/empty (loader uses HEADER true).
        $relPath = "$outdir/CONCEPT_RELATIONSHIP_CUSTOM.csv";
        $needHeader = ! file_exists($relPath) || filesize($relPath) === 0;
        $relFh = fopen($relPath, 'a');
        if ($needHeader) {
            fwrite($relFh, implode("\t", ['concept_id_1', 'concept_id_2', 'relationship_id', 'valid_start_date', 'valid_end_date', 'invalid_reason'])."\n");
        }
        $reviewFh = fopen("$outdir/cihi_maps_review.tsv", 'w');
        fwrite($reviewFh, "snomed_code\tcanadian_code\treason\n");

        $today = now()->format('Ymd');
        $in = fopen($refset, 'r');
        $mapped = 0;
        $skipped = 0;

        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$snomedCode, $canadianCode] = array_pad(explode("\t", $line), 2, '');
            $snomedCode = trim($snomedCode);
            $canadianCode = trim($canadianCode);
            if ($snomedCode === '' || $canadianCode === '') {
                continue;
            }

            // SNOMED target must be a standard, valid concept already loaded.
            $target = DB::table('concepts')
                ->where('vocabulary_id', 'SNOMED')->where('concept_code', $snomedCode)
                ->where('standard_concept', 'S')->whereNull('invalid_reason')
                ->value('concept_id');

            $sourceId = $ids->existingId($vocab, $canadianCode);

            if ($target === null) {
                fwrite($reviewFh, "$snomedCode\t$canadianCode\tSNOMED target not standard/loaded\n");
                $skipped++;

                continue;
            }
            if ($sourceId === null) {
                fwrite($reviewFh, "$snomedCode\t$canadianCode\tCanadian code not in registry (run convert-cihi first)\n");
                $skipped++;

                continue;
            }

            fwrite($relFh, implode("\t", [$sourceId, $target, 'Maps to', $today, '20991231', ''])."\n");
            $mapped++;
        }

        foreach ([$in, $relFh, $reviewFh] as $fh) {
            fclose($fh);
        }

        $this->info("Wrote $mapped Maps-to row(s); $skipped pair(s) need review (see cihi_maps_review.tsv).");

        return self::SUCCESS;
    }
}
