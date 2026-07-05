<?php

namespace App\Console\Commands;

use App\Services\CustomIdAllocator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Convert a licensed Canadian classification file into COMET *_CUSTOM.csv
 * (concept + FR synonyms + vocabulary) in the 2-billion space, with stable
 * ids via custom_concept_ids. Drop the output beside your Athena download and
 * comet:load-vocab ingests it in the same atomic swap.
 *
 * Input is a normalized tab-delimited file (real CIHI/Infoway distributions
 * vary and are licensed; convert them to this shape first — see the fixtures
 * in db/fixtures/cihi_sample and docs/vocab-refresh.md):
 *
 *   icd10ca | cci : code<TAB>name_en<TAB>name_fr(optional)
 *   snomedca      : code<TAB>name_en<TAB>domain<TAB>name_fr(optional)   (standard)
 *   pclocd        : code<TAB>name_en<TAB>name_fr(optional)              (source)
 *
 *   php artisan comet:convert-cihi icd10ca storage/cihi/icd10ca.tsv storage/out
 */
class ConvertCihi extends Command
{
    protected $signature = 'comet:convert-cihi {type : icd10ca|cci|snomedca|pclocd} {input : normalized TSV} {outdir : directory for *_CUSTOM.csv}';

    protected $description = 'Convert a Canadian classification file to COMET custom-vocabulary CSVs';

    private const FR_LANG = 4181536; // OMOP language_concept_id for French

    /** @var array<string, array{vocab:string, class:string, domain:?string, standard:string}> */
    private const TYPES = [
        'icd10ca' => ['vocab' => 'ICD10CA', 'class' => 'ICD10CA code', 'domain' => 'Condition', 'standard' => ''],
        'cci' => ['vocab' => 'CCI', 'class' => 'CCI code', 'domain' => 'Procedure', 'standard' => ''],
        'snomedca' => ['vocab' => 'SNOMED', 'class' => 'Clinical Finding', 'domain' => null, 'standard' => 'S'],
        'pclocd' => ['vocab' => 'pCLOCD', 'class' => 'Lab Test', 'domain' => 'Measurement', 'standard' => ''],
    ];

    public function handle(CustomIdAllocator $ids): int
    {
        $type = $this->argument('type');
        if (! isset(self::TYPES[$type])) {
            $this->error('type must be one of: '.implode(', ', array_keys(self::TYPES)));

            return self::FAILURE;
        }
        $cfg = self::TYPES[$type];
        $input = $this->argument('input');
        if (! is_readable($input)) {
            $this->error("Input not readable: $input");

            return self::FAILURE;
        }

        $outdir = rtrim($this->argument('outdir'), '/');
        if (! is_dir($outdir) && ! mkdir($outdir, 0775, true) && ! is_dir($outdir)) {
            throw new RuntimeException("Cannot create outdir: $outdir");
        }

        $conceptFh = fopen("$outdir/CONCEPT_CUSTOM.csv", 'w');
        $synFh = fopen("$outdir/CONCEPT_SYNONYM_CUSTOM.csv", 'w');
        $vocabFh = fopen("$outdir/VOCABULARY_CUSTOM.csv", 'w');

        // Header rows: the loader COPYs *_CUSTOM.csv with HEADER true (same as
        // Athena files), so the first line must be a header or it is skipped.
        fwrite($conceptFh, implode("\t", ['concept_id', 'concept_name', 'domain_id', 'vocabulary_id', 'concept_class_id', 'standard_concept', 'concept_code', 'valid_start_date', 'valid_end_date', 'invalid_reason'])."\n");
        fwrite($synFh, implode("\t", ['concept_id', 'concept_synonym_name', 'language_concept_id'])."\n");
        fwrite($vocabFh, implode("\t", ['vocabulary_id', 'vocabulary_name', 'vocabulary_reference', 'vocabulary_version', 'vocabulary_concept_id'])."\n");

        fwrite($vocabFh, implode("\t", [$cfg['vocab'], self::vocabName($type), self::vocabRef($type), 'CIHI/Infoway', $ids->idFor('__VOCAB__', $cfg['vocab'])])."\n");

        $today = now()->format('Ymd');
        $in = fopen($input, 'r');
        $concepts = 0;
        $synonyms = 0;

        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $cols = explode("\t", $line);
            $code = trim($cols[0]);
            $nameEn = trim($cols[1] ?? '');
            if ($code === '' || $nameEn === '') {
                continue;
            }

            if ($type === 'snomedca') {
                $domain = trim($cols[2] ?? 'Observable Entity') ?: 'Condition';
                $nameFr = trim($cols[3] ?? '');
            } else {
                $domain = $cfg['domain'];
                $nameFr = trim($cols[2] ?? '');
            }

            $conceptId = $ids->idFor($cfg['vocab'], $code);
            fwrite($conceptFh, implode("\t", [
                $conceptId, $nameEn, $domain, $cfg['vocab'], $cfg['class'], $cfg['standard'], $code, $today, '20991231', '',
            ])."\n");
            $concepts++;

            if ($nameFr !== '') {
                fwrite($synFh, implode("\t", [$conceptId, $nameFr, self::FR_LANG])."\n");
                $synonyms++;
            }
        }

        foreach ([$in, $conceptFh, $synFh, $vocabFh] as $fh) {
            fclose($fh);
        }

        $this->info("Wrote $concepts concept(s) + $synonyms FR synonym(s) to $outdir (vocabulary {$cfg['vocab']}).");
        $this->line('Place these beside your Athena download and run comet:load-vocab.');

        return self::SUCCESS;
    }

    private static function vocabName(string $type): string
    {
        return [
            'icd10ca' => 'ICD-10-CA (CIHI)',
            'cci' => 'Canadian Classification of Health Interventions (CIHI)',
            'snomedca' => 'SNOMED CT Canadian Edition',
            'pclocd' => 'pan-Canadian LOINC Observation Code Database',
        ][$type];
    }

    private static function vocabRef(string $type): string
    {
        return str_starts_with($type, 'p') ? 'https://infoway-inforoute.ca' : 'https://www.cihi.ca';
    }
}
