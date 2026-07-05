# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

COMET (Central Online Mapping and Export Tool) — a legacy procedural PHP 8.2 web app for PHSA that maps Cerner source terminology ("MR sheets") to OMOP standard concepts. No framework, no Composer, no tests, no linter. All application code lives in `public/`, which is also the web document root.

## Running the app

```bash
cp .env.example .env                # first time only; also edit secrets/*.txt passwords
docker compose up -d --build        # or: podman compose up -d
# app served at http://localhost:8080
docker compose logs -f app          # PHP-FPM logs
docker compose exec db mysql -ucomet_app -p$(cat secrets/comet_app_pw.txt) shaye067_phsa_db
```

Three services (`docker-compose.yml`): `web` (nginx 1.25, port 8080) → `app` (php-fpm 8.2-alpine, pdo_mysql/mysqli/intl) → `db` (MySQL 8.4).

- `./public` is bind-mounted into both `web` and `app`, so PHP/JS/CSS edits are live immediately — no rebuild needed. Rebuild only when changing `docker/php/Dockerfile`.
- DB credentials come from file-based secrets: `secrets/db_root_pw.txt` and `secrets/comet_app_pw.txt` (mounted at `/run/secrets/`). `public/db.php` and `login.php` read `/run/secrets/comet_app_pw` directly, with `DB_HOST`/`MYSQL_DATABASE`/`MYSQL_USER` overridable via env (defaults: `db` / `shaye067_phsa_db` / `comet_app`).
- The schema+data is seeded from `db/init/shaye067_phsa_db1.sql` only on **first** startup of an empty volume. To reseed: `docker compose down -v && docker compose up -d`.
- Schema changes since the seed dump live in `db/migrations/*.sql`, applied by a tracked runner: `docker compose exec app php /var/www/bin/migrate.php` (idempotent; records applied files in `comet_migrations`). Run this after any fresh `up`.

## OMOP vocabulary & mapping acceleration

The vocabulary reference is loaded from OHDSI Athena, not the seed dump. `bin/load_vocab.php` bulk-loads `CONCEPT`/`CONCEPT_SYNONYM`/`CONCEPT_RELATIONSHIP`/`VOCABULARY` into `*_stg` staging tables and swaps them in atomically (`RENAME TABLE`); the live vintage is recorded in `comet_vocab_meta` and shown on the home page. Full procedure in `docs/vocab-refresh.md`; a tiny end-to-end fixture is `db/fixtures/athena_sample/` (load it with `docker compose exec app php /var/www/bin/load_vocab.php /var/www/fixtures/athena_sample dev`). `local-infile` is enabled in `db/conf.d/import.cnf` for this.

Mapping helpers added to `common.php` (all prepared-statement based): `resolve_concept()`, `is_valid_map_target()` (CDM v5.4 rule: `standard_concept='S'` and `invalid_reason IS NULL`), `get_standard_replacements()` (resolves `Maps to`/`Concept replaced by`), `build_target_rejection_msg()`, `find_maps_for_description()` / `find_unmapped_rows_for_description()` (duplicate-map propagation). New endpoints/pages: `search_concept.php` (in-editor JSON concept search over names+synonyms), `vocab_impact.php` (reviewer: post-refresh report of maps whose targets became deprecated/non-standard, with one-click remap), `export_stcm.php` (valid OMOP `SOURCE_TO_CONCEPT_MAP` CSV, live or per-release), `manage_releases.php` (admin: named, vocab-tagged map releases in `comet_map_releases`/`comet_map_release_maps`). The add/update handlers in `edit_mr_item.php` and `edit_review_item.php` now block non-standard/invalid targets and offer replacements; `index_source.php` computes per-sheet progress live rather than trusting the drift-prone `phsa_mr_sheets` counters (admin "recompute counters" action rewrites them).

Note: migration `003_fix_maps_hx.sql` fixed a latent bug — the seed dump's `phsa_all_maps_hx` had NOT-NULL columns with no default that made every `log_map_hx()` insert fail silently under MySQL 8.4 strict mode, so no change history was written until this was applied.

## Architecture

Every page is a standalone PHP script in `public/` following the same pattern: `require db.php` + `common.php`, `my_session_start()`, `verify_session()`, validate `$_GET`/`$_POST` with `check_expected()`, open its own PDO connection, then emit HTML inline.

- `common.php` — the shared layer: session/auth functions, `clean_input()` sanitization, and the data-loading helpers (`set_sources_arr`, `set_targets_arr`, `set_phsa_maps_arr`, `match_up_targets_with_maps`) that assemble the mapping-grid rows, plus audit logging (`log_map_hx`).
- Navigation: `index.php` (menu) → `index_source.php` (list of Cerner sheets) or `index_domain.php` (OMOP domains) → `list_mr.php` / `list_domain.php` (paginated mapping grids) → `edit_mr_item.php` / `edit_review_item.php` loaded via jQuery `$().load()` into a slide-over div (see `comet_ops.js`). `search_sheet.php` is the AJAX concept-search endpoint.
- Import pipeline: `import_mr_txt.php` (UI) drives `import_mr_txt_ajx_f.php` / `import_mr_txt_ajx_b.php` in chunked AJAX rounds, loading tab-delimited Cerner extracts from `public/mr_data/*.txt`. Requires the importer privilege.
- Exports: `export_maps.php`, `export_exclusions.php`.

### Database (key tables)

- `phsa_mr_sheets` / `phsa_mr_data` / `phsa_mr_data_src_cd_desc` / `phsa_mr_attr_data` — imported Cerner source terms; each data row has up to 6 source code "spots".
- `omop_concept` — OMOP vocabulary reference.
- `phsa_mr_data_targets` — target concept chosen per data row; `phsa_all_maps` — the actual maps, with full audit trail in `phsa_all_maps_hx` (every change goes through `log_map_hx()`).
- `phsa_users` — accounts with `mapper` / `importer` / `reviewer` / `portal_admin` flags, surfaced as `$_SESSION["PHSA_PRIV_*"]` at login.
- Sheet ids 15–19 (Event / Result sheets) are hard-coded in `common.php::list_mr_create_tr()` for cross-referencing tooltips.

### Auth

Session-based; `login.php` (the one modernized file: strict types, prepared statements, `password_verify`) sets `PHSA_*` session vars; `verify_session()` in `common.php` enforces a 30-minute keyed timeout. **`DEV_AUTH_BYPASS` is currently `1` in `common.php`, disabling all auth checks — set it back to `0` for anything production-bound.**

## Conventions and cautions

- Except for `login.php`, SQL is built by string interpolation into `$pdo->query()` — inputs are only guarded by `check_expected()`, `is_numeric()` checks, and `clean_input()` quote-stripping. When writing new code, use PDO prepared statements (as `login.php` does) rather than extending this pattern.
- One-off/destructive scripts (`create_map_table.php`, importers) carry a commented-out `die("Security Lock!...")` first line as a safety latch — preserve/uncomment it when the script isn't in active use.
- `old/`, `sql/`, `src/`, and `temp.sh` are scaffolding leftovers; the live code is entirely under `public/`. `public/error_log` and `import_sql_log*.txt` are runtime artifacts.
- Newer code (`bin/`, `search_concept.php`, `vocab_impact.php`, `export_stcm.php`, `manage_releases.php`, `common.php` mapping helpers, the rewritten add/update handlers) uses PDO prepared statements and `ENT_QUOTES` output escaping. Follow that style; do not extend the legacy string-interpolation pattern.
- The git repo is initialized (`main`). `.gitignore` excludes `.env`, `secrets/`, the DB dump (`db/init/`), Cerner extracts (`public/mr_data/`), Athena downloads (`vocab_data/`), and runtime logs.
