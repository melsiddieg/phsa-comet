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
- Not a git repository (as of 2026-07); no version control history to consult.
