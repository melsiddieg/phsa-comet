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

## Canadian (BC) vocabulary extensions

The vocabularies most relevant to a BC/PHSA deployment are **not in Athena's
standard set** and are separately licensed:

| Vocabulary | What it is | Source / licence |
|---|---|---|
| **ICD-10-CA** | Canadian diagnosis classification | CIHI (licence required) |
| **CCI** | Canadian Classification of Health Interventions (procedures) | CIHI (licence required) |
| **SNOMED CT-CA** | Canadian edition of SNOMED CT (Canadian-extension concepts) | Canada Health Infoway / SNOMED International |
| **pCLOCD** | pan-Canadian LOINC Observation Code Database | Canada Health Infoway |
| **DPD / DIN** | Health Canada Drug Product Database identifiers | Health Canada |

BC-local code sets (facility/location codes, MSP fee items, PharmaNet, etc.)
are **source terms** — they come in through the MappingReport import and are
mapped inside COMET; they are not vocabulary extensions. Where no standard
target exists for a Canadian code, use the term's **SDO Submission** status to
queue it for the OHDSI vocabulary team rather than forcing a wrong target.

### How OMOP represents these

Athena keeps the international standards (SNOMED CT International, LOINC, RxNorm,
UCUM, …). Canadian classifications are loaded as **custom source vocabularies**
using the OHDSI **2-billion convention**: every custom concept gets a
`concept_id >= 2,000,000,000` (Athena-managed ids stay below that, so there is
no collision), a non-standard `standard_concept` (they are *source* codes), and
a `Maps to` relationship to the standard concept it maps to. CIHI already
publishes **SNOMED CT-CA → ICD-10-CA / CCI** map refsets (120,000+ rules) that
can seed those `Maps to` rows — with the appropriate ICD-10-CA (CIHI) and
SNOMED CT (Infoway) licences.

### Loading them with COMET

`comet:load-vocab` automatically picks up optional **`*_CUSTOM.csv`** files
placed in the **same directory** as the Athena download and appends them into
the same atomic swap — so custom concepts share the release's vintage and are
**re-applied on every refresh** (they are not wiped when you reload Athena):

```
vocab_data/athena_2026q2/
  CONCEPT.csv                       # Athena
  CONCEPT_SYNONYM.csv
  CONCEPT_RELATIONSHIP.csv
  VOCABULARY.csv
  CONCEPT_CUSTOM.csv                # Canadian extensions (optional)
  CONCEPT_SYNONYM_CUSTOM.csv
  CONCEPT_RELATIONSHIP_CUSTOM.csv
  VOCABULARY_CUSTOM.csv
```

Each `*_CUSTOM.csv` has the **same tab-delimited columns** as its Athena
counterpart. Minimal example — register ICD-10-CA as a source vocabulary and
map `I10` to the standard SNOMED concept for essential hypertension:

```
# VOCABULARY_CUSTOM.csv
vocabulary_id  vocabulary_name    vocabulary_reference   vocabulary_version  vocabulary_concept_id
ICD10CA        ICD-10-CA (CIHI)   https://www.cihi.ca    CIHI 2024           2000000000

# CONCEPT_CUSTOM.csv   (standard_concept blank = source code; concept_id >= 2e9)
concept_id   concept_name                       domain_id  vocabulary_id  concept_class_id  standard_concept  concept_code  valid_start_date  valid_end_date  invalid_reason
2000000001   Essential (primary) hypertension   Condition  ICD10CA        ICD10CA code                        I10           20220401          20991231

# CONCEPT_RELATIONSHIP_CUSTOM.csv   (Canadian source -> OMOP standard)
concept_id_1  concept_id_2  relationship_id  valid_start_date  valid_end_date  invalid_reason
2000000001    320128        Maps to          20220401          20991231
```

Then load as usual:

```bash
docker compose exec app php artisan comet:load-vocab /vocab_data/athena_2026q2 --by="Your Name"
```

The loader reports `(includes N custom/extension concept rows)` and warns if any
staged concept sits in `[1e9, 2e9)` (a sign a custom id wasn't lifted into the
2-billion range). A worked fixture lives in `db/fixtures/athena_sample/` (the
`*_CUSTOM.csv` files there add an ICD-10-CA concept mapping to the standard
SNOMED hypertension concept).

### Converters (CIHI/Infoway → `*_CUSTOM.csv`)

Rather than hand-authoring the `*_CUSTOM.csv`, convert a licensed distribution
with the built-in commands. They allocate **stable** 2-billion ids via the
`custom_concept_ids` registry (re-runs produce identical ids, so maps stay
valid across releases) and emit header rows (the loader reads `*_CUSTOM.csv`
with `HEADER true`).

```bash
# classification -> concept + FR synonyms + vocabulary CSVs
#   input is a normalized TSV (convert the real, licensed file to this first):
#     icd10ca|cci : code<TAB>name_en<TAB>name_fr(optional)
#     snomedca    : code<TAB>name_en<TAB>domain<TAB>name_fr(optional)   (standard)
#     pclocd      : code<TAB>name_en<TAB>name_fr(optional)              (source)
docker compose exec app php artisan comet:convert-cihi icd10ca /var/www/vocab_data/cihi/icd10ca.tsv /var/www/vocab_data/athena_2026q2

# CIHI SNOMED CT-CA map refset (snomed_code<TAB>canadian_code) -> Maps-to rows.
# Resolves the SNOMED code to a *standard* concept already loaded from Athena
# and the Canadian code to its registry id; unresolved pairs go to
# cihi_maps_review.tsv for manual attention.
docker compose exec app php artisan comet:convert-cihi-maps ICD10CA /var/www/vocab_data/cihi/refset.tsv /var/www/vocab_data/athena_2026q2
```

Write the output straight into the same directory as the Athena download, then
`comet:load-vocab` that directory. (Run `convert-cihi` before
`convert-cihi-maps`, and `convert-cihi-maps` after the SNOMED Athena vocabulary
is loaded, so the standard targets resolve.) A worked fixture is
`db/fixtures/cihi_sample/`.

Notes:
- Keep the licensed raw files under `vocab_data/` (git-ignored) — do not commit
  CIHI/Infoway/Health-Canada content.
- Every `*_CUSTOM.csv` (hand-made or converter-produced) must carry a header row.
- Add each custom `vocabulary_id` (e.g. `ICD10CA`) to the relevant sheet's
  allowed vocabularies (`sheet_vocabularies`) so mappers can select it.
- Because the custom rows ride the atomic swap, keep the `*_CUSTOM.csv` files in
  the download directory for **every** refresh; if you drop them, the next load
  won't include them.

## Cadence

Refresh **quarterly**, or when the OHDSI vocabulary team announces a release
that affects the vocabularies you map to
(<https://github.com/OHDSI/Vocabulary-v5.0/releases>). Always run the impact
report immediately after, and consider cutting a named map release beforehand so
you have a frozen, pre-refresh baseline to compare against.
