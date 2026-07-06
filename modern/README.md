# COMET (modern)

**C**entral **O**nline **M**apping and **E**xport **T**ool — maps PHSA Cerner
source terminology to standard OMOP CDM v5.4 concepts, with first-class support
for Canadian vocabularies (ICD-10-CA, CCI, SNOMED CT-CA, pCLOCD).

This is the Laravel 12 + PostgreSQL 16 rebuild of the legacy procedural-PHP app
(which still lives in `../public`). Server-rendered with Livewire; SSO via
Entra ID; OMOP-native (STCM output, standard/valid guardrails, vocabulary
versioning, custom 2-billion concepts).

## Start here

- **Setting up / resuming on a new machine → [`../RESUME.md`](../RESUME.md).**
- Vocabulary loading & Canadian extensions → [`docs/vocab-refresh.md`](docs/vocab-refresh.md)
- Mapper workflow → [`docs/mapper-guide.md`](docs/mapper-guide.md)
- Reviewer workflow → [`docs/reviewer-guide.md`](docs/reviewer-guide.md)

## Quick reference

```bash
# from modern/ with the compose stack up (see RESUME.md for first-run):
podman exec comet_modern_app php artisan migrate --force
podman exec comet_modern_app php artisan db:seed --force            # break-glass admin
podman exec comet_modern_app php artisan comet:load-vocab /vocab_data/<dir> --by=you
podman exec comet_modern_app php artisan comet:auto-map --all
podman exec comet_modern_app php artisan test                        # 68 tests
```

App: http://localhost:8081 · Stack: nginx → php-fpm 8.4 → Postgres 16 + Redis
+ queue worker.

Roles: `mapper` / `importer` / `reviewer` / `portal_admin` — new SSO users get
none until a portal admin grants them (User Administration).
