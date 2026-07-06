# COMET — resume guide

Everything needed to pick this project up on another machine. Read this first.

## TL;DR of where things stand

- **Two apps in this repo.** `public/` is the **legacy** procedural-PHP app
  (still runnable, port 8080). `modern/` is the **rebuild** — Laravel 12 +
  PostgreSQL 16 (port 8081) — which is the app going forward. All new work is in
  `modern/`.
- **Rebuild is feature-complete** through milestones **M0–M7** (port + parity)
  and enhancement phases **P1–P5** (Canadian-mapping power tool). **68 tests
  passing, Larastan clean.** See the commit log for the per-phase breakdown.
- **What's blocked on storage (why we're moving machines):** the app has only
  been exercised against a tiny **9-concept dev fixture**. Concept search,
  the OMOP-domain view, the auto-mapper, and the impact report only become
  meaningful once a **full Athena vocabulary** (multi-GB) is loaded. That's the
  first task on the new machine.

## Data you must bring (all git-ignored — NOT in the clone)

A fresh `git clone` will **not** contain these. Copy them from this machine (or
re-obtain), into the same paths:

| Path | What | Needed for |
|------|------|-----------|
| `secrets/*.txt` | DB passwords (`db_root_pw`, `comet_app_pw`, `pg_app_pw`) | both stacks — **regenerate, see below** |
| `modern/.env` | Laravel env (`APP_KEY`, Entra, legacy-DB creds) | modern app — **recreate from `.env.example`** |
| `.env` | legacy stack env | legacy app only |
| `db/init/shaye067_phsa_db1.sql` | 45 MB legacy MySQL dump | only if you want `comet:migrate-legacy` / `comet:verify-parity` |
| `public/mr_data/*.txt` | Cerner MappingReport extracts | only if you want to test the import pipeline |
| `vocab_data/<dir>/` | Athena vocabulary download | **the main reason for the move** — download fresh on the new machine |

`secrets/` and env files are trivial to recreate. The three data artifacts
(legacy dump, mr_data, Athena download) are large/external — decide which you
need (see "Getting real data in" below).

## Fresh-machine bring-up (modern app)

Prereqs: `podman` (or Docker) + compose, `git`, ~30 GB free for a full vocab.

```bash
git clone git@github.com:melsiddieg/phsa-commet.git commet && cd commet

# 1. Secrets (values are arbitrary for local; pg one just has to be consistent)
mkdir -p secrets
openssl rand -hex 16 > secrets/pg_app_pw.txt
openssl rand -hex 16 > secrets/comet_app_pw.txt   # legacy stack only
openssl rand -hex 16 > secrets/db_root_pw.txt     # legacy stack only
chmod 600 secrets/*.txt

# 2. Modern env
cd modern
cp .env.example .env
mkdir -p ../vocab_data          # writable mount for Athena + converter output

# 3. Bring the stack up (nginx:8081, php-fpm 8.4, postgres 16, redis, queue worker)
podman-compose up -d --build    # or: docker compose up -d --build
podman exec comet_modern_app php artisan key:generate
podman exec comet_modern_app php artisan migrate --force
podman exec comet_modern_app php artisan db:seed --force   # break-glass admin

# 4. Log in at http://localhost:8081
#    Local sign-in (break-glass):  admin@comet.local  /  change-me-now
```

At this point the app runs but has **no source terms and only the dev vocab
fixture**. Load real data next.

## Getting real data in

**A. Source terms (the Cerner "MR sheets") — pick one:**
- *From the legacy dump:* place `db/init/shaye067_phsa_db1.sql`, bring up the
  legacy stack (`docker compose up -d` at repo root), then bridge + migrate:
  ```bash
  podman network connect commet_comet_net comet_modern_app        # legacy net
  # set LEGACY_DB_HOST=comet_db and LEGACY_DB_PASSWORD in modern/.env
  podman exec comet_modern_app php artisan comet:migrate-legacy --fresh
  ```
  This loads ~189k terms + 16k maps and preserves ids for `comet:verify-parity`.
- *From MappingReport files:* place `public/mr_data/*.txt`, then use the
  **Import** UI (importer role) per sheet — queued, tracked in `import_runs`.

**B. The OMOP vocabulary (do this first for real testing):**
1. Download from <https://athena.ohdsi.org> (SNOMED, LOINC, RxNorm, UCUM, the
   sheet vocabularies…). Unzip into `vocab_data/<dir>/`.
2. Load it (staged, atomic swap):
   ```bash
   podman exec comet_modern_app php artisan comet:load-vocab /vocab_data/<dir> --by="you"
   ```
3. Full procedure + Canadian extensions (ICD-10-CA/CCI/SNOMED-CA/pCLOCD via the
   `comet:convert-cihi` converters): **`modern/docs/vocab-refresh.md`**.

**C. Precompute candidates** once vocab + terms are in:
```bash
podman exec comet_modern_app php artisan comet:auto-map --all   # queued
```
Then Mapping Mode opens with instant ranked candidates.

## Architecture (modern app) — the mental model

- **Stack:** Laravel 12 / PHP 8.4, Postgres 16, Redis (queue), Livewire 3.8
  (server-rendered, no SPA). Blade layouts: `layouts/app` (@yield) for
  controllers, `components/layouts/app` (slot) for full-page Livewire.
- **Auth:** Entra ID SSO via Socialite (`AuthController`), JIT provisioning with
  **zero roles** until an admin grants them; break-glass local admin gated by
  `AUTH_LOCAL_LOGIN`. Roles are four Gates (`map`/`import`/`review`/`admin`) in
  `AppServiceProvider`.
- **Schema (13 migrations):** `sheets` (+ `sheet_source_columns` normalizing the
  legacy 6 flat "spots"), `source_terms`/`source_term_codes`/`_attributes`,
  STCM-shaped `maps` (+ `equivalence`, nullable source snapshot for unlinked
  maps), append-only `map_audits`, `map_snapshots`, `map_candidates`,
  `releases`/`release_maps`, `import_runs`, `concepts`/`concept_synonyms`/
  `concept_relationships`/`concept_ancestors`/`vocabularies`/`vocab_meta`,
  `custom_concept_ids` (stable 2B ids), `custom` team columns on `source_terms`.
- **Key services (`modern/app/Services`):** `ConceptSearch` (hybrid trigram +
  tsvector + unaccent), `ConceptDetail`, `MapGuardrails` (CDM v5.4 rules),
  `MapAuditor`, `ReviewService`, `SheetProgress`, `TeamStats`, `ClaimService`,
  `MapExporter`, `ReleaseService`, `CustomIdAllocator`.
- **Artisan (`comet:*`):** `load-vocab`, `migrate-legacy`, `verify-parity`,
  `auto-map`, `convert-cihi`, `convert-cihi-maps`.
- **Screens:** home (progress + milestones + returned-to-you), Cerner Areas /
  sheet grid / Mapping Mode / term editor, OMOP Domains, Concepts browser,
  Review queue, Vocabulary Impact, Team dashboard, Import, Releases/Users admin,
  exports (STCM / Usagi / Exclusions / SDO).

## Roadmap — what's next (in rough priority)

1. **Load a full vocabulary and re-validate** (the move's purpose): after
   `load-vocab`, sanity-check search quality on real terms (incl. a French
   synonym), the OMOP-domain view populating, the impact report showing genuine
   deprecations, and `auto-map --all` producing good candidates. Tune
   `config('comet.search')` weights if needed.
2. **Turn on Entra SSO:** register the app in the tenant, fill `ENTRA_*` in
   `modern/.env`, set `AUTH_LOCAL_LOGIN=0`, change `BREAK_GLASS_PASSWORD`, and
   rotate the seeded break-glass password.
3. **Production compose overlay:** pinned image digests, no bind mounts (bake
   code into the image), `display_errors=Off`, TLS termination in front of
   nginx, `restart: always`, healthchecks; nightly `pg_dump` backups with a
   tested restore.
4. **CI gap:** the GitHub Actions workflow runs tests on **SQLite**, so the
   Postgres-only search paths (`f_unaccent`, tsvector, `pg_trgm`) aren't
   exercised in CI. Add a Postgres service to the workflow and a
   `@requires pgsql` search test.
5. **Nice-to-haves / deferred:** richer hierarchy-browse UI off
   `concept_ancestors`; OpenSearch backend if pg search proves insufficient at
   scale; DQD-style quality checks on maps; per-mapper formal assignments (we
   chose lightweight claims). LLM-assisted suggestions were **explicitly
   declined** — keep mapping deterministic/lexical unless that changes.

## Known debts / gotchas

- Repo is named `phsa-commet` (double-m) while the app is "COMET" — cosmetic.
- `*_CUSTOM.csv` files **must have a header row** (loader COPYs with
  `HEADER true`); the converters emit them, hand-made ones need them too.
- Two separate compose stacks: legacy at repo root (`comet_*`, net
  `commet_comet_net`), modern in `modern/` (`comet_modern_*`). `migrate-legacy`
  needs the app container bridged onto the legacy network.
- After changing the PHP Dockerfile, **rebuild the `queue` container too** — it
  shares the image; a stale queue worker silently fails jobs.
- The legacy app has `DEV_AUTH_BYPASS=1` (dev only) — irrelevant to the modern
  app, which has real auth.

## Test & verify (any machine)

```bash
podman exec comet_modern_app php artisan test                       # 68 tests
podman exec comet_modern_app vendor/bin/phpstan analyse --memory-limit=1G
podman exec comet_modern_app php artisan comet:verify-parity        # vs legacy (if loaded)
```

Guides: `modern/docs/mapper-guide.md`, `modern/docs/reviewer-guide.md`,
`modern/docs/vocab-refresh.md`.
