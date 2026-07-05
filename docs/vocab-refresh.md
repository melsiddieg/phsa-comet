# OMOP vocabulary refresh procedure

COMET maps to OMOP standard concepts, so the local vocabulary tables
(`omop_concept`, `omop_concept_synonym`, `omop_concept_relationship`,
`omop_vocabulary`) must track the OHDSI Athena releases. Refresh
**quarterly**, or when the OHDSI vocabulary team announces a release that
affects SNOMED / LOINC (see <https://github.com/OHDSI/Vocabulary-v5.0/releases>).

## 1. Download from Athena

1. Sign in at <https://athena.ohdsi.org> (free account).
2. Choose **Download** and select the vocabularies the team maps to — at
   minimum: `SNOMED`, `LOINC`, `RxNorm`, `UCUM`, `Gender`, `Race`,
   `Ethnicity`, `CMS Place of Service`, `Medicare Specialty`, `NUCC`,
   plus any others listed in `phsa_mr_sheet_vocabularies`.
3. CPT4 (if needed) requires the extra license step: Athena emails a bundle
   that needs `cpt.sh` run with a UMLS API key. Skip unless a sheet maps to CPT4.
4. Wait for the email, download the zip, and unzip it into a directory under
   `vocab_data/` in this repo (the directory is git-ignored and mounted
   read-only into the app container at `/var/www/vocab_data`).

## 2. Load

```bash
# one-time after pulling this version: create the vocabulary tables
docker compose exec app php /var/www/bin/migrate.php

# load (staged: the app keeps serving the old vocabulary until the atomic swap)
docker compose exec app php /var/www/bin/load_vocab.php /var/www/vocab_data/<unzipped-dir> "<your name>"
```

The loader:
- bulk-loads `CONCEPT.csv`, `CONCEPT_SYNONYM.csv`, `CONCEPT_RELATIONSHIP.csv`,
  `VOCABULARY.csv` into `*_stg` staging tables (`LOAD DATA LOCAL INFILE`);
- keeps only the relationship types COMET uses (`Maps to`, `Mapped from`,
  `Concept replaced by`, `Concept poss_eq to`, `Concept same_as to`);
- validates row counts and date formats, then swaps the tables in with a
  single `RENAME TABLE` (no downtime, no half-loaded state);
- records the release in `comet_vocab_meta` — the current release is shown on
  the COMET home page.

Expect the full load to take a while on first run (CONCEPT alone is millions
of rows); the FULLTEXT indexes are built during the staging load.

## 3. Review the impact

After every refresh, open **Vocabulary Impact Report** (`vocab_impact.php`)
in COMET. It lists every existing map whose target concept is now deprecated
(`invalid_reason` set) or no longer standard (`standard_concept <> 'S'`),
with the replacement suggested by the vocabulary's `Concept replaced by` /
`Maps to` relationships. Remap or send each to the Question queue — per
OHDSI practice, maps should only point at concepts that are standard and
valid in the *current* release.

## Local development

A tiny fixture in `db/fixtures/athena_sample/` (mounted at
`/var/www/fixtures/athena_sample`) exercises the whole pipeline without a
real Athena download:

```bash
docker compose exec app php /var/www/bin/load_vocab.php /var/www/fixtures/athena_sample dev
```
