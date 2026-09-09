# COMET (modern)

**C**entral **O**nline **M**apping and **E**xport **T**ool — maps PHSA Cerner
source terminology to standard OMOP CDM v5.4 concepts, with first-class support
for Canadian vocabularies (ICD-10-CA, CCI, SNOMED CT-CA, pCLOCD).

This is the Laravel 12 + PostgreSQL 16 rebuild of the legacy procedural-PHP app
(which still lives in `../public`). Server-rendered with Livewire; SSO via
Entra ID; OMOP-native (STCM output, standard/valid guardrails, vocabulary
versioning, custom 2-billion concepts).

## Start here

- **Deploying to a server → [`docker/production/README.md`](docker/production/README.md)** —
  Docker Compose, TLS, backups, upgrades. Start there for anything production-bound.
- **Local dev / resuming on a new machine → [`../RESUME.md`](../RESUME.md).**
- Vocabulary loading & Canadian extensions → [`docs/vocab-refresh.md`](docs/vocab-refresh.md)
- Mapper workflow → [`docs/mapper-guide.md`](docs/mapper-guide.md)
- Reviewer workflow → [`docs/reviewer-guide.md`](docs/reviewer-guide.md)
- Deploying to Azure instead → [`docs/azure-deploy.md`](docs/azure-deploy.md)

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

## Deployment at a glance

Production runs from one image in three roles (`app` / `queue` / `migrate`),
built by `docker/production/Dockerfile`:

```bash
# on the server, from modern/
alias comet='docker compose -f docker/production/compose.yaml -f docker/production/compose.caddy.yaml --env-file .env.production'
comet build && comet up -d
comet ps          # `migrate` showing exited (0) is success
```

Full walkthrough — server prep, secrets, TLS, systemd, backups, upgrades — in
[`docker/production/README.md`](docker/production/README.md).
