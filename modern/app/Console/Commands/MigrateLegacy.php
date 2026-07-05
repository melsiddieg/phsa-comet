<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot migration of the legacy MySQL COMET database into the normalized
 * Postgres schema.
 *
 * Reads the `legacy_mysql` connection (see config/database.php). When both
 * stacks run under podman, connect the app container to the legacy network
 * first:  podman network connect commet_comet_net comet_modern_app
 * then run with LEGACY_DB_HOST=comet_db.
 *
 * Legacy ids are preserved (sheets, source terms, maps) so exports can be
 * diffed against the legacy app during parallel-run validation. Vocabulary
 * tables are NOT migrated - load a real Athena release with comet:load-vocab.
 * Historical audit rows arrive pre-approved ("grandfathered") so the review
 * queue starts empty, matching legacy behavior after its migration 005.
 */
class MigrateLegacy extends Command
{
    protected $signature = 'comet:migrate-legacy {--fresh : wipe target tables first}';

    protected $description = 'Migrate the legacy MySQL COMET database into Postgres';

    private const CHUNK = 2000;

    public function handle(): int
    {
        $legacy = DB::connection('legacy_mysql');

        try {
            $sheetCount = $legacy->table('phsa_mr_sheets')->count();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the legacy MySQL database: '.$e->getMessage());
            $this->line('Hint: podman network connect commet_comet_net comet_modern_app  (and set LEGACY_DB_HOST=comet_db, LEGACY_DB_PASSWORD)');

            return self::FAILURE;
        }

        $this->info("Legacy DB reachable ($sheetCount sheets). Starting migration...");

        if ($this->option('fresh')) {
            $this->wipeTargets();
        }

        DB::transaction(function () use ($legacy) {
            $this->migrateSheets($legacy);
            $this->migrateSourceTerms($legacy);
            $this->migrateMaps($legacy);
            $this->migrateAudits($legacy);
            $this->migrateReleases($legacy);
            $this->migrateDataDictionary($legacy);
        });

        $this->fixSequences();
        $this->report();

        return self::SUCCESS;
    }

    private function wipeTargets(): void
    {
        $this->warn('Wiping target tables (--fresh)...');
        // Delete in FK-safe order.
        foreach ([
            'release_maps', 'releases', 'map_snapshots', 'map_audits', 'maps',
            'suggested_targets', 'source_term_comments', 'source_term_attributes',
            'source_term_codes', 'source_terms', 'sheet_cross_references',
            'sheet_domains', 'sheet_vocabularies', 'sheet_attributes',
            'sheet_source_columns', 'sheets',
            'dd_target_table_columns', 'dd_source_target_links', 'dd_target_tables', 'dd_source_tables',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function migrateSheets($legacy): void
    {
        $this->line('Sheets + configuration...');

        foreach ($legacy->table('phsa_mr_sheets')->orderBy('id')->get() as $s) {
            DB::table('sheets')->insert([
                'id' => $s->id,
                'name' => $s->name,
                'auto_map_exists' => ($s->auto_map_exists ?? 'No') === 'Yes',
                'last_import_at' => $s->last_import_date,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Normalize the 6 flat source "spots"
            for ($i = 1; $i <= 6; $i++) {
                $voc = $s->{"src_voc_name_$i"} ?? null;
                if ($voc === null || $voc === '') {
                    break;
                }
                DB::table('sheet_source_columns')->insert([
                    'sheet_id' => $s->id,
                    'spot' => $i,
                    'vocabulary' => $voc,
                    'code_label' => $s->{"src_cd_colname_$i"} ?? '',
                    'desc_label' => $s->{"src_desc_colname_$i"} ?? '',
                ]);
            }
        }

        foreach ($legacy->table('phsa_mr_sheets_attr')->get() as $a) {
            DB::table('sheet_attributes')->insert([
                'id' => $a->id,
                'sheet_id' => $a->sheet_id,
                'name' => $a->attr_name,
                'col_position' => $a->col_position,
            ]);
        }

        foreach ($legacy->table('phsa_mr_sheet_vocabularies')->get() as $v) {
            DB::table('sheet_vocabularies')->insertOrIgnore([
                'sheet_id' => $v->sheet_id,
                'vocabulary' => $v->vocabulary,
            ]);
        }

        foreach ($legacy->table('phsa_mr_sheet_domains')->get() as $d) {
            DB::table('sheet_domains')->insertOrIgnore([
                'sheet_id' => $d->sheet_id,
                'domain_id' => $d->domain_id,
            ]);
        }

        // Generalize the legacy hard-coded Event/Result cross-references
        // (common.php::list_mr_create_tr, sheet ids 15-19).
        $xrefs = [
            // Event (15): look up ER sheets (18,19) by spot1=event code, follow spot2 into Results (16,17)
            ['sheet_id' => 15, 'via_sheet_id' => 18, 'via_spot_self' => 1, 'via_spot_other' => 2, 'other_sheet_id' => 16, 'label' => 'R'],
            ['sheet_id' => 15, 'via_sheet_id' => 19, 'via_spot_self' => 1, 'via_spot_other' => 2, 'other_sheet_id' => 17, 'label' => 'R'],
            // Result (Cerner Code) (16): via ER (18), spot2=result code, follow spot1 into Event (15)
            ['sheet_id' => 16, 'via_sheet_id' => 18, 'via_spot_self' => 2, 'via_spot_other' => 1, 'other_sheet_id' => 15, 'label' => 'E'],
            // Result (Nomenclature) (17): via ER (19)
            ['sheet_id' => 17, 'via_sheet_id' => 19, 'via_spot_self' => 2, 'via_spot_other' => 1, 'other_sheet_id' => 15, 'label' => 'E'],
        ];
        $existing = DB::table('sheets')->pluck('id')->all();
        foreach ($xrefs as $x) {
            if (in_array($x['sheet_id'], $existing) && in_array($x['via_sheet_id'], $existing) && in_array($x['other_sheet_id'], $existing)) {
                DB::table('sheet_cross_references')->insert($x);
            }
        }
    }

    private function migrateSourceTerms($legacy): void
    {
        $this->line('Source terms (chunked)...');

        $legacy->table('phsa_mr_data')->orderBy('id')->chunk(self::CHUNK, function ($rows) {
            $terms = [];
            foreach ($rows as $r) {
                $terms[] = [
                    'id' => $r->id,
                    'sheet_id' => $r->sheet_id,
                    'total_count' => $r->total_count,
                    'map_source' => $r->map_source ?: null,
                    'mr_status' => $r->mr_status ?: null,
                    'exclude_status' => $r->exclude ?: null,
                    'inserted_by' => $r->inserted_by,
                    'updated_by' => $r->updated_by,
                    'created_at' => $r->inserted_dt ?? now(),
                    'updated_at' => $r->updated_dt ?? $r->inserted_dt ?? now(),
                ];
            }
            DB::table('source_terms')->insert($terms);
        });

        $legacy->table('phsa_mr_data_src_cd_desc')->orderBy('data_id')->orderBy('spot')
            ->chunk(self::CHUNK, function ($rows) {
                $codes = [];
                foreach ($rows as $r) {
                    $codes[] = [
                        'source_term_id' => $r->data_id,
                        'spot' => $r->spot,
                        'code' => $r->code,
                        'description' => $r->description,
                    ];
                }
                DB::table('source_term_codes')->insertOrIgnore($codes);
            });

        $legacy->table('phsa_mr_attr_data')->orderBy('data_id')
            ->chunk(self::CHUNK, function ($rows) {
                $attrs = [];
                foreach ($rows as $r) {
                    $attrs[] = [
                        'source_term_id' => $r->data_id,
                        'sheet_attribute_id' => $r->attr_id,
                        'value' => $r->value,
                    ];
                }
                DB::table('source_term_attributes')->insert($attrs);
            });

        foreach ($legacy->table('phsa_mr_data_comments')->get() as $c) {
            DB::table('source_term_comments')->insertOrIgnore([
                'source_term_id' => $c->data_id,
                'comment_text' => $c->comment_text,
                'created_by' => $c->created_by,
                'updated_by' => $c->updated_by,
                'created_at' => $c->created_dttm,
                'updated_at' => $c->updated_dttm,
            ]);
        }

        $legacy->table('phsa_mr_data_targets')->orderBy('data_id')
            ->chunk(self::CHUNK, function ($rows) {
                $targets = [];
                foreach ($rows as $r) {
                    if (is_numeric($r->concept_id)) {
                        $targets[] = ['source_term_id' => $r->data_id, 'concept_id' => $r->concept_id];
                    }
                }
                DB::table('suggested_targets')->insert($targets);
            });
    }

    private function migrateMaps($legacy): void
    {
        $this->line('Maps (chunked; source codes live on the term)...');

        $unlinked = 0;
        $skipped = 0;
        $legacy->table('phsa_all_maps')->orderBy('id')->chunk(self::CHUNK, function ($rows) use (&$unlinked, &$skipped) {
            $termIds = DB::table('source_terms')
                ->whereIn('id', collect($rows)->pluck('src_data_id')->filter()->all())
                ->pluck('id')->flip();

            $maps = [];
            foreach ($rows as $r) {
                if (! is_numeric($r->target_concept_id)) {
                    $skipped++;

                    continue;
                }

                $linked = $r->src_data_id && isset($termIds[$r->src_data_id]);
                if (! $linked) {
                    // Map whose source term dropped out of later MR imports:
                    // keep it, carrying its own source snapshot (it still
                    // belongs in the STCM export).
                    $unlinked++;
                    $snapshot = [];
                    for ($i = 1; $i <= 6; $i++) {
                        $code = $r->{"source_code_$i"} ?? null;
                        if ($code !== null && $code !== '') {
                            $snapshot[] = [
                                'spot' => $i,
                                'vocabulary' => $r->{"source_vocabulary_id_$i"},
                                'code' => $code,
                                'description' => $r->{"source_code_description_$i"},
                            ];
                        }
                    }
                }

                $maps[] = [
                    'id' => $r->id,
                    'source_term_id' => $linked ? $r->src_data_id : null,
                    'source_code' => $linked ? null : $r->source_code_1,
                    'source_vocabulary_id' => $linked ? null : $r->source_vocabulary_id_1,
                    'source_code_description' => $linked ? null : $r->source_code_description_1,
                    'source_snapshot' => $linked ? null : json_encode($snapshot),
                    'target_concept_id' => (int) $r->target_concept_id,
                    'target_concept_name' => mb_substr((string) $r->target_concept_name, 0, 400),
                    'target_vocabulary_id' => (string) $r->target_vocabulary_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('maps')->insert($maps);
        });

        if ($unlinked) {
            $this->line("  $unlinked maps have no current source term (source dropped from later MR imports); kept with their own source snapshot");
        }
        if ($skipped) {
            $this->warn("  skipped $skipped rows with non-numeric target_concept_id");
        }
    }

    private function migrateAudits($legacy): void
    {
        $this->line('Audit history (grandfathered as already-reviewed)...');

        $legacy->table('phsa_all_maps_hx')->orderBy('date_time')->chunk(self::CHUNK, function ($rows) {
            $audits = [];
            foreach ($rows as $r) {
                if (! $r->src_data_id || ! DB::table('source_terms')->where('id', $r->src_data_id)->exists()) {
                    continue;
                }
                $snapshot = [];
                for ($i = 1; $i <= 6; $i++) {
                    $code = $r->{"source_code_$i"} ?? null;
                    if ($code !== null && $code !== '') {
                        $snapshot[] = [
                            'spot' => $i,
                            'vocabulary' => $r->{"source_vocabulary_id_$i"},
                            'code' => $code,
                            'description' => $r->{"source_code_description_$i"},
                        ];
                    }
                }
                $audits[] = [
                    'map_id' => $r->map_id ?: null,
                    'source_term_id' => $r->src_data_id,
                    'action' => $r->change_action,
                    'target_concept_id' => is_numeric($r->target_concept_id) ? (int) $r->target_concept_id : null,
                    'target_concept_name' => $r->target_concept_name !== '' ? mb_substr((string) $r->target_concept_name, 0, 400) : null,
                    'target_vocabulary_id' => $r->target_vocabulary_id !== '' ? $r->target_vocabulary_id : null,
                    'before_target_concept_id' => is_numeric($r->before_update_target_concept_id) ? (int) $r->before_update_target_concept_id : null,
                    'source_snapshot' => json_encode($snapshot),
                    'username' => $r->username,
                    // Grandfather: pre-migration history must not flood the review queue
                    'approved_by' => ($r->approved_by !== null && $r->approved_by !== '') ? $r->approved_by : 'migration',
                    'approved_at' => $r->approved_dttm ?: now(),
                    'created_at' => $r->date_time,
                ];
            }
            DB::table('map_audits')->insert($audits);
        });
    }

    private function migrateReleases($legacy): void
    {
        // comet_map_releases may not exist on older legacy DBs
        try {
            $releases = $legacy->table('comet_map_releases')->get();
        } catch (\Throwable) {
            return;
        }

        $this->line('Map releases...');
        foreach ($releases as $r) {
            DB::table('releases')->insert([
                'id' => $r->id,
                'name' => $r->name,
                'vocab_release' => $r->vocab_release,
                'notes' => $r->notes,
                'created_by' => $r->created_by,
                'map_count' => $r->map_count,
                'created_at' => $r->created_at,
            ]);
        }

        $legacy->table('comet_map_release_maps')->orderBy('id')->chunk(self::CHUNK, function ($rows) {
            $maps = [];
            foreach ($rows as $r) {
                $maps[] = [
                    'release_id' => $r->release_id,
                    'source_term_id' => $r->src_data_id,
                    'source_code' => $r->source_code,
                    'source_vocabulary_id' => $r->source_vocabulary_id,
                    'source_code_description' => $r->source_code_description,
                    'target_concept_id' => is_numeric($r->target_concept_id) ? (int) $r->target_concept_id : 0,
                    'target_concept_name' => $r->target_concept_name,
                    'target_vocabulary_id' => $r->target_vocabulary_id,
                ];
            }
            DB::table('release_maps')->insert($maps);
        });
    }

    private function migrateDataDictionary($legacy): void
    {
        $this->line('CDM data dictionary...');

        foreach ($legacy->table('phsa_source_tables')->get() as $t) {
            DB::table('dd_source_tables')->insert([
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description,
            ]);
        }
        foreach ($legacy->table('phsa_target_tables')->get() as $t) {
            DB::table('dd_target_tables')->insert([
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'scoping_rule' => $t->{'scoping rule'} ?? null,
                'notes' => $t->notes,
                'mauro_link' => $t->mauro_link,
            ]);
        }
        foreach ($legacy->table('phsa_src_tgt_tables')->get() as $l) {
            if (DB::table('dd_source_tables')->where('id', $l->src_table_id)->exists()
                && DB::table('dd_target_tables')->where('id', $l->tgt_table_id)->exists()) {
                DB::table('dd_source_target_links')->insert([
                    'dd_source_table_id' => $l->src_table_id,
                    'dd_target_table_id' => $l->tgt_table_id,
                    'scoping_rule' => $l->scoping_rule,
                    'scoping_jira' => $l->scoping_jira,
                    'transformation_rule' => $l->transformation_rule,
                    'transformation_jira' => $l->transformation_jira,
                ]);
            }
        }
        foreach ($legacy->table('phsa_target_table_columns')->get() as $c) {
            if (! DB::table('dd_target_tables')->where('id', $c->table_id)->exists()) {
                continue;
            }
            DB::table('dd_target_table_columns')->insert([
                'dd_target_table_id' => $c->table_id,
                'column_name' => $c->column_name,
                'description' => $c->description,
                'datatype' => $c->datatype,
                'required' => (bool) $c->required,
                'is_pk' => (bool) $c->is_pk,
                'is_fk' => (bool) $c->is_fk,
                'fk_table' => $c->fk_table,
                'fk_domain' => $c->fk_domain,
                'etl_notes' => $c->etl_notes,
            ]);
        }
    }

    /** Preserved legacy ids leave Postgres sequences behind - advance them. */
    private function fixSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['sheets', 'sheet_attributes', 'source_terms', 'maps', 'releases', 'dd_source_tables', 'dd_target_tables'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM $table), 1))");
        }
    }

    private function report(): void
    {
        $this->newLine();
        $this->info('Migration complete:');
        foreach ([
            'sheets', 'sheet_source_columns', 'source_terms', 'source_term_codes',
            'source_term_attributes', 'suggested_targets', 'maps', 'map_audits',
            'releases', 'dd_target_tables',
        ] as $table) {
            $this->line(sprintf('  %-24s %d', $table, DB::table($table)->count()));
        }
        $this->line('Vocabulary tables are empty by design - run comet:load-vocab next.');
    }
}
