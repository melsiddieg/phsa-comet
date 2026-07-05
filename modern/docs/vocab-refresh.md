# Loading & refreshing the OMOP vocabulary (modern app)

COMET maps to OMOP standard concepts, so the local vocabulary tables
(`concepts`, `concept_synonyms`, `concept_relationships`, `vocabularies`) must
track the OHDSI Athena releases. The app ships with only a tiny **8-concept dev
fixture** — until a real Athena release is loaded, concept search returns almost
nothing, the *Mapped Terms by OMOP Domain* page shows only the handful of maps
whose target happens to be in the fixture, and the impact report flags nearly
every map as "missing from vocabulary". All of that is expected and resolves
once you load a full vocabulary.

> Legacy note: the old PHP app used `bin/load_vocab.php` into MySQL. The modern
> app uses the `comet:load-vocab` artisan command into PostgreSQL. This document
> covers the modern app.

## 1. Download from Athena

1. Sign in at <https://athena.ohdsi.org> (free account).
2. **Download** → select the vocabularies the team maps to. At minimum the ones
   referenced by `sheet_vocabularies` — typically `SNOMED`, `LOINC`, `RxNorm`,
   `UCUM`, `Gender`, `Race`, `Ethnicity`, `CMS Place of Service`,
   `Medicare Specialty`, `NUCC`. **Load the full set the team uses** — a partial
   download makes valid targets in the omitted vocabularies look "missing".
3. CPT4 (if a sheet maps to it) needs the extra license step: Athena emails a
   bundle that must be finalized by running its `cpt.sh` with a UMLS API key.
4. Unzip the download into the `vocab_data/` directory at the **repo root**
   (git-ignored; a `.gitkeep` marks it). The Postgres container sees it at
   `/vocab_data/<your-unzipped-dir>` via the read-only mount in
   `modern/docker-compose.yml`.

The files the loader reads (tab-delimited, as Athena ships them): `CONCEPT.csv`,
`CONCEPT_SYNONYM.csv`, `CONCEPT_RELATIONSHIP.csv`, `VOCABULARY.csv`.

## 2. Load

```bash
# path is as seen INSIDE the Postgres container (server-side COPY)
docker compose exec app php artisan comet:load-vocab /vocab_data/<unzipped-dir> --by="Your Name"

# dev fixture (tiny, for local smoke-testing):
docker compose exec app php artisan comet:load-vocab /fixtures/athena_sample --by=dev
```

(Use `podman compose` if that's your runtime.)

The loader is **staged and atomic** — the app keeps serving the current
vocabulary until the new one is fully built and swapped in:

1. bulk-`COPY` each CSV into a `*_stg` staging table;
2. prune `concept_relationships` to the types COMET needs
   (`Maps to`, `Mapped from`, `Concept replaced by`, `Concept poss_eq to`,
   `Concept same_as to`) and drop invalid rows;
3. validate row counts and date formats;
4. build indexes on the staging tables, including the `pg_trgm` GIN indexes that
   power concept search over names + synonyms;
5. swap the staging tables in inside a single transaction (Postgres DDL is
   transactional — no half-loaded state, no downtime);
6. record the load in `vocab_meta`.

First load of a real release takes a while (CONCEPT alone is millions of rows).

### File-format gotcha

Postgres `COPY` requires every row to have the full column count. Athena's files
are already well-formed, but if you hand-edit a file or use the fixture as a
template, make sure trailing empty columns are present as trailing tabs — a row
missing its final (empty) `invalid_reason`/`standard_concept` field will fail the
load. (MySQL's `LOAD DATA` tolerated missing trailing fields; Postgres does not.)

## 3. Review the impact

After every refresh, open **Impact** (Vocabulary Impact Report, reviewer role).
It lists every existing map whose target became **deprecated**
(`invalid_reason` set), **non-standard** (`standard_concept <> 'S'`), or
**missing** from the new release, with the replacement suggested by the
vocabulary's `Concept replaced by` / `Maps to` relationships. Remap or send each
to the Question queue — per OHDSI practice, maps should only point at concepts
that are standard and valid in the *current* release.

## How versioning is handled

- **`vocab_meta`** is the ledger. Every `comet:load-vocab` run inserts a row:
  the Athena release string, who loaded it, the concept count, and the load
  timestamp. The current release is whatever row has the highest `id`.
- **Where the release string comes from:** Athena stamps the overall release on
  the `VOCABULARY.csv` row whose `vocabulary_id` is `None`
  (e.g. `v5.0 27-JUN-26`); the loader reads that into `vocab_meta.athena_release`.
  If it's absent it falls back to `unknown (<load-date>)`.
- **Where it's shown:** the current release badge appears on *Source Terms by
  Cerner Area*, *Mapped Terms by OMOP Domain*, and the *Impact* report, so
  mappers always know which vintage they're working against.
- **Releases are pinned to a vintage.** When an admin creates a map release
  (*Releases* page), the current `vocab_meta.athena_release` is copied onto the
  `releases` row and the maps are frozen into `release_maps`. A per-release STCM
  export therefore reproduces exactly the maps *and* the vocabulary vintage they
  were validated against — reproducible for a downstream ETL even after the live
  vocabulary is later refreshed.
- **No history is destroyed on refresh.** Loading a new release replaces the
  reference `concepts`/`synonyms`/`relationships` tables, but your maps
  (`maps`), their audit trail (`map_audits`), and any frozen releases are
  untouched — a refresh only changes what the impact report and domain views
  *say about* those maps, never the maps themselves.

## Cadence

Refresh **quarterly**, or when the OHDSI vocabulary team announces a release
that affects the vocabularies you map to
(<https://github.com/OHDSI/Vocabulary-v5.0/releases>). Always run the impact
report immediately after, and consider cutting a named map release beforehand so
you have a frozen, pre-refresh baseline to compare against.
