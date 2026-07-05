<?php

namespace App\Services;

use App\Models\ImportRun;
use App\Models\Sheet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports a Cerner "MappingReport" tab-delimited extract into a sheet.
 *
 * Faithful port of the legacy import_mr_txt.php, with two upgrades: an
 * in-memory term index (replacing the per-row EXISTS queries — much faster on
 * 50k-row sheets) and a persistent import_runs audit (replacing the single
 * overwritten log file).
 *
 * Behavior preserved:
 *  - validate the file header against the sheet's configured source columns,
 *    attribute positions, and a required "OMOP Concept ID" column;
 *  - match rows to existing terms by their full set of source codes;
 *  - upsert term + codes + attributes + suggested target (the MappingReport's
 *    own OMOP Concept ID column) + total_count + map source;
 *  - MR drift: New in MR / Still in MR / Absent in latest MR;
 *  - relink previously-unlinked maps whose source reappears.
 */
class MappingReportImporter
{
    private const OMOP_ID_COLUMN = 'OMOP Concept ID';

    public function import(Sheet $sheet, string $path, string $startedBy): ImportRun
    {
        if (! is_readable($path)) {
            throw new RuntimeException("File not readable: $path");
        }

        $run = ImportRun::create([
            'sheet_id' => $sheet->id,
            'filename' => basename($path),
            'status' => 'running',
            'started_by' => $startedBy,
            'started_at' => now(),
        ]);

        try {
            $counts = DB::transaction(fn () => $this->process($sheet, $path, $run, $startedBy));

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'rows_total' => $counts['total'],
                'rows_new' => $counts['new'],
                'rows_updated' => $counts['updated'],
                'rows_absent' => $counts['absent'],
            ]);
            $sheet->update(['last_import_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
            throw $e;
        }

        return $run->refresh();
    }

    /** @return array{total:int,new:int,updated:int,absent:int} */
    private function process(Sheet $sheet, string $path, ImportRun $run, string $startedBy): array
    {
        $spots = $sheet->sourceColumns()->orderBy('spot')->get();
        $attrs = $sheet->sheetAttributes()->orderBy('col_position')->get();

        $handle = fopen($path, 'r');
        $header = $this->readRow($handle);
        if (! $header) {
            throw new RuntimeException('File is empty.');
        }
        // 1-based column index (legacy convention).
        $colIndex = [];
        foreach ($header as $i => $name) {
            $name = trim($name);
            if ($name === '') {
                break;
            }
            $colIndex[$name] = $i + 1;
        }

        $this->validateHeader($colIndex, $spots, $attrs);

        // In-memory index of existing terms by their code signature.
        $index = $this->buildTermIndex($sheet->id);

        $new = 0;
        $updated = 0;
        $total = 0;

        while (($row = $this->readRow($handle)) !== null) {
            array_unshift($row, ''); // shift to 1-based
            $total++;

            $codes = [];
            foreach ($spots as $spot) {
                $codes[$spot->spot] = $row[$colIndex[$spot->code_label]] ?? '';
            }
            $signature = $this->signature($codes);

            $conceptId = $this->numericOrNull($row[$colIndex[self::OMOP_ID_COLUMN]] ?? null);
            $totalCount = $this->parseCount($row, $colIndex);
            $mapSource = $this->mapSource($row, $colIndex, $conceptId);

            if (isset($index[$signature])) {
                $termId = $index[$signature];
                $this->updateTerm($termId, $row, $spots, $attrs, $colIndex, $codes, $conceptId, $totalCount, $mapSource, $run->id, $startedBy);
                $updated++;
            } else {
                $termId = $this->insertTerm($sheet->id, $row, $spots, $attrs, $colIndex, $codes, $conceptId, $totalCount, $mapSource, $run->id, $startedBy);
                $index[$signature] = $termId;
                $new++;
            }

            $this->relinkOrphanMaps($termId, $codes[1] ?? '', $spots->firstWhere('spot', 1)?->vocabulary);
        }
        fclose($handle);

        // MR drift: anything not seen this run is now absent.
        $absent = DB::table('source_terms')
            ->where('sheet_id', $sheet->id)
            ->where(fn ($q) => $q->whereNull('last_seen_import_run_id')->orWhere('last_seen_import_run_id', '!=', $run->id))
            ->update(['mr_status' => 'Absent in latest MR']);

        return ['total' => $total, 'new' => $new, 'updated' => $updated, 'absent' => $absent];
    }

    private function validateHeader($colIndex, $spots, $attrs): void
    {
        foreach ($attrs as $a) {
            $names = array_flip($colIndex); // position => name
            if (($names[$a->col_position] ?? null) !== $a->name) {
                throw new RuntimeException("Attribute column '{$a->name}' not at expected position {$a->col_position}.");
            }
        }
        foreach ($spots as $spot) {
            foreach ([$spot->code_label, $spot->desc_label] as $label) {
                if (! isset($colIndex[$label])) {
                    throw new RuntimeException("Column '$label' not found in MappingReport.");
                }
            }
        }
        if (! isset($colIndex[self::OMOP_ID_COLUMN])) {
            throw new RuntimeException("Column '".self::OMOP_ID_COLUMN."' not found in MappingReport.");
        }
    }

    /** @return array<string, int> code-signature => term_id */
    private function buildTermIndex(int $sheetId): array
    {
        $rows = DB::table('source_terms as t')
            ->join('source_term_codes as c', 'c.source_term_id', '=', 't.id')
            ->where('t.sheet_id', $sheetId)
            ->orderBy('t.id')->orderBy('c.spot')
            ->get(['t.id', 'c.spot', 'c.code']);

        $bySpot = [];
        foreach ($rows as $r) {
            $bySpot[$r->id][$r->spot] = $r->code;
        }

        $index = [];
        foreach ($bySpot as $termId => $codes) {
            ksort($codes);
            $index[$this->signature($codes)] = $termId;
        }

        return $index;
    }

    private function signature(array $codes): string
    {
        ksort($codes);

        return implode('|', array_map(fn ($c) => trim((string) $c), $codes));
    }

    private function updateTerm(int $termId, array $row, $spots, $attrs, array $colIndex, array $codes, ?int $conceptId, ?int $totalCount, ?string $mapSource, int $runId, string $user): void
    {
        foreach ($spots as $spot) {
            DB::table('source_term_codes')
                ->where('source_term_id', $termId)->where('spot', $spot->spot)
                ->update(['description' => $row[$colIndex[$spot->desc_label]] ?? null]);
        }

        DB::table('suggested_targets')->where('source_term_id', $termId)->delete();
        if ($conceptId !== null) {
            DB::table('suggested_targets')->insert(['source_term_id' => $termId, 'concept_id' => $conceptId]);
        }

        DB::table('source_term_attributes')->where('source_term_id', $termId)->delete();
        $this->insertAttributes($termId, $row, $attrs, $colIndex);

        DB::table('source_terms')->where('id', $termId)->update([
            'total_count' => $totalCount,
            'map_source' => $mapSource,
            'mr_status' => 'Still in MR',
            'last_seen_import_run_id' => $runId,
            'updated_by' => $user,
            'updated_at' => now(),
        ]);
    }

    private function insertTerm(int $sheetId, array $row, $spots, $attrs, array $colIndex, array $codes, ?int $conceptId, ?int $totalCount, ?string $mapSource, int $runId, string $user): int
    {
        $termId = DB::table('source_terms')->insertGetId([
            'sheet_id' => $sheetId,
            'total_count' => $totalCount,
            'map_source' => $mapSource,
            'mr_status' => 'New in MR',
            'last_seen_import_run_id' => $runId,
            'inserted_by' => $user,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($spots as $spot) {
            DB::table('source_term_codes')->insert([
                'source_term_id' => $termId,
                'spot' => $spot->spot,
                'code' => $codes[$spot->spot] ?? '',
                'description' => $row[$colIndex[$spot->desc_label]] ?? null,
            ]);
        }

        if ($conceptId !== null) {
            DB::table('suggested_targets')->insert(['source_term_id' => $termId, 'concept_id' => $conceptId]);
        }
        $this->insertAttributes($termId, $row, $attrs, $colIndex);

        return $termId;
    }

    private function insertAttributes(int $termId, array $row, $attrs, array $colIndex): void
    {
        foreach ($attrs as $a) {
            DB::table('source_term_attributes')->insert([
                'source_term_id' => $termId,
                'sheet_attribute_id' => $a->id,
                'value' => $row[$a->col_position] ?? null,
            ]);
        }
    }

    /** Reconnect a previously-unlinked map when its source term reappears. */
    private function relinkOrphanMaps(int $termId, string $code1, ?string $vocab1): void
    {
        if ($code1 === '') {
            return;
        }
        DB::table('maps')
            ->whereNull('source_term_id')
            ->where('source_code', $code1)
            ->when($vocab1, fn ($q) => $q->where('source_vocabulary_id', $vocab1))
            ->update([
                'source_term_id' => $termId,
                'source_code' => null,
                'source_vocabulary_id' => null,
                'source_code_description' => null,
                'source_snapshot' => null,
            ]);
    }

    private function readRow($handle): ?array
    {
        $line = fgets($handle);
        if ($line === false) {
            return null;
        }
        // Cerner extracts are Windows-1252 (e.g. 0xA0 non-breaking space) which
        // legacy MySQL tolerated but Postgres/UTF-8 rejects. Transcode invalid
        // lines before they reach the database.
        if (! mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
        }
        $line = str_replace(["\r", "\n", '"'], '', $line);
        if (trim($line) === '') {
            return null;
        }

        return explode("\t", $line);
    }

    private function numericOrNull($value): ?int
    {
        $value = trim((string) $value);

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseCount(array $row, array $colIndex): ?int
    {
        if (! isset($colIndex['Count'])) {
            return null;
        }
        $raw = str_replace(',', '', trim((string) ($row[$colIndex['Count']] ?? '')));

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function mapSource(array $row, array $colIndex, ?int $conceptId): ?string
    {
        if (isset($colIndex['Reviewed'])) {
            $reviewed = trim((string) ($row[$colIndex['Reviewed']] ?? ''));
            if ($reviewed === '') {
                return null;
            }

            return $reviewed === 'Auto' ? 'Auto' : 'User';
        }

        return $conceptId !== null ? 'User' : null;
    }
}
