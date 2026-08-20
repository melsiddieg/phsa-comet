# COMET — Production Deployment (Docker Compose)

Self-hosted production deployment of the COMET mapping application using
Docker Compose. Everything runs on one host: app, queue worker, PostgreSQL
and Redis.

Tested with **Docker Compose v2.26.1** (Compose Specification, `docker compose`
— note the space, not the legacy `docker-compose`).

---

## Contents

1. [Architecture](#architecture)
2. [Requirements](#requirements)
3. [Quick start](#quick-start)
4. [Configuration](#configuration)
5. [Loading data](#loading-data)
6. [Day-2 operations](#day-2-operations)
7. [Backup and restore](#backup-and-restore)
8. [Upgrades and rollback](#upgrades-and-rollback)
9. [TLS / reverse proxy](#tls--reverse-proxy)
10. [Security checklist](#security-checklist)
11. [Troubleshooting](#troubleshooting)

---

## Architecture

```
                    ┌───────────────────────────────────────┐
   :8080  ────────► │ app      nginx + php-fpm (supervisord)│
                    │          CONTAINER_ROLE=app           │
                    └────────────┬──────────────┬───────────┘
                                 │              │
   ┌─────────────────────────────┼──────────────┼────────────┐
   │ migrate  (one-shot, exits 0)│              │            │
   │ CONTAINER_ROLE=migrate      │              │            │
   └─────────────────────────────┤              │            │
                                 │              │            │
                    ┌────────────▼──────┐  ┌────▼─────────┐  │
                    │ db   Postgres 16  │  │ redis 7      │◄─┤
                    │ vol: pg_data      │  │ vol:redis_data│ │
                    └───────────────────┘  └──────────────┘  │
                                                             │
                    ┌────────────────────────────────────────┴┐
                    │ queue    php artisan queue:work          │
                    │          CONTAINER_ROLE=queue            │
                    └──────────────────────────────────────────┘
```

**One image, three roles.** `app`, `queue` and `migrate` are the *same* image
(`docker/production/Dockerfile`) with a different `CONTAINER_ROLE`. They can
never drift out of sync.

**Migrations run exactly once.** The `migrate` service runs `php artisan
migrate --force` and exits 0. `app` and `queue` declare
`depends_on: {migrate: {condition: service_completed_successfully}}`, so they
only start after schema changes have landed — no races, no partial upgrades.

**Code is baked into the image**, not bind-mounted. That is what lets opcache
run with `validate_timestamps=0` (best performance) and guarantees the running
code matches the built artifact.

| Service | Restart | Ports | Volumes |
|---------|---------|-------|---------|
| `db` | unless-stopped | internal only | `pg_data`, `./backups`, vocab (ro) |
| `redis` | unless-stopped | internal only | `redis_data` |
| `migrate` | no (one-shot) | — | — |
| `app` | unless-stopped | `${HTTP_PORT}:8080` | `app_storage`, vocab |
| `queue` | unless-stopped | none | `app_storage`, vocab |

---

## Requirements

| | Minimum | Recommended |
|---|---|---|
| Docker Engine | 24.0 | 26.0+ |
| Docker Compose | v2.20 | **v2.26.1+** |
| RAM | 4 GB | 8 GB+ |
| Disk | 20 GB | **100 GB+** (a full Athena vocabulary is tens of GB) |
| CPU | 2 cores | 4+ cores |

```bash
docker --version && docker compose version
```

> **Architecture:** build on the same CPU architecture you deploy to, or pass
> `--platform`. An arm64 image (Apple Silicon) will not run on an amd64 server.

---

## Quick start

### 1. Get the code

```bash
git clone https://github.com/melsiddieg/phsa-commet.git commet
cd commet/modern
```

### 2. Create the database password secret

The Postgres password is a **file-based Docker secret**, never an environment
variable — `config/database.php` reads `/run/secrets/pg_app_pw` directly.

```bash
mkdir -p ../secrets
openssl rand -hex 24 > ../secrets/pg_app_pw.txt
chmod 600 ../secrets/pg_app_pw.txt
```

### 3. Create the environment file

```bash
cp docker/production/.env.production.example .env.production
chmod 600 .env.production
```

Edit it and set **at minimum**:

```bash
# Generate the app key — save it, changing it invalidates every session
echo "APP_KEY=base64:$(openssl rand -base64 32)"
```

| Must set | Why |
|----------|-----|
| `APP_KEY` | Encryption/session key. Generate once, **never rotate casually**. |
| `APP_URL` | Public URL. Wrong value breaks SSO redirects and generated links. |

### 4. Build and start

```bash
docker compose -f docker/production/compose.yaml --env-file .env.production build
docker compose -f docker/production/compose.yaml --env-file .env.production up -d
```

The first build takes several minutes (it compiles the `intl` PHP extension).
Compose builds the shared image **once** and reuses it for all three roles.

### 5. Verify

```bash
docker compose -f docker/production/compose.yaml --env-file .env.production ps
curl -f http://localhost:8080/up && echo "  COMET is up"
```

All services should be `running`; `migrate` should show `exited (0)` — that
is success, not a failure.

### 6. Create the first admin

```bash
docker compose -f docker/production/compose.yaml --env-file .env.production \
  exec app php artisan db:seed --force
```

This creates the break-glass admin `admin@comet.local` / `change-me-now`.
**Log in and change that password immediately**, then move to Entra SSO and
set `AUTH_LOCAL_LOGIN=0`.

---

### Shell shortcut

The full command is long. Define it once per session:

```bash
alias comet='docker compose -f docker/production/compose.yaml --env-file .env.production'
```

The rest of this README uses `comet` to mean exactly that.

---

## Configuration

All settings live in `.env.production` (read by Compose, **not** baked into
the image). Full list in `.env.production.example`.

### Required

| Variable | Notes |
|----------|-------|
| `APP_KEY` | `base64:...`, 32 random bytes. Compose refuses to start without it. |
| `APP_URL` | e.g. `https://comet.phsa.ca` |

### Common

| Variable | Default | Notes |
|----------|---------|-------|
| `HTTP_PORT` | `8080` | Use `127.0.0.1:8080` behind a reverse proxy |
| `DB_DATABASE` / `DB_USERNAME` | `comet` / `comet_app` | Set **before first start**; changing later needs a new volume |
| `LOG_LEVEL` | `warning` | `debug` is noisy and may log sensitive values |
| `COMET_IMAGE` | `comet:latest` | Point at a registry tag to skip local builds |
| `VOCAB_DATA_DIR` | `../../../vocab_data` | Host dir with Athena CSVs; relative to `docker/production/` |
| `PG_PASSWORD_FILE` | `../../../secrets/pg_app_pw.txt` | Relative to `docker/production/` |

> **Relative paths resolve from the compose file's directory**
> (`modern/docker/production/`), not your shell's working directory. Use
> absolute paths if that is ambiguous in your setup.

### Entra ID SSO

| Variable | Notes |
|----------|-------|
| `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` / `ENTRA_TENANT_ID` | From the app registration |
| `AUTH_LOCAL_LOGIN` | `0` disables break-glass login — do this once SSO works |

Set the redirect URI in Entra to `${APP_URL}/auth/callback`.

Applying config changes:

```bash
comet up -d          # recreates only what changed
```

---

## Loading data

### OMOP vocabulary (Athena)

1. Download from <https://athena.ohdsi.org> and unzip into the host directory
   pointed at by `VOCAB_DATA_DIR` (default `vocab_data/` at the repo root).
2. Load it — staged tables plus an atomic swap, so the app stays live:

```bash
comet exec app php artisan comet:load-vocab /var/www/vocab_data/<dir> --by="you"
comet exec app php artisan comet:vocab-status --search="hypertension"
```

Sizing: a full multi-vocabulary load needs **tens of GB** in the `pg_data`
volume. Check free space first.

### Canadian vocabularies

```bash
comet exec app php artisan comet:convert-cihi      --help
comet exec app php artisan comet:convert-cihi-maps --help
```

These emit `*_CUSTOM.csv` files into `vocab_data/`, loaded with the same
`comet:load-vocab` command.

### Source terms

From the legacy MySQL database (needs network reachability to it):

```bash
comet exec app php artisan comet:migrate-legacy --fresh
comet exec app php artisan comet:verify-parity
```

Or import Cerner MappingReport files through the **Import** screen in the UI
(requires the importer role).

### Precompute mapping candidates

```bash
comet exec app php artisan comet:auto-map --all     # queued; watch the worker
comet logs -f queue
```

---

## Day-2 operations

```bash
comet ps                       # service status
comet logs -f app              # follow app logs
comet logs -f queue            # follow worker logs
comet logs --tail=200 migrate  # what the last migration did

comet restart app              # restart one service
comet exec app sh              # shell inside the app container
comet exec db psql -U comet_app -d comet     # psql

comet stop                     # stop, keep data
comet down                     # remove containers, keep volumes
```

> `comet down -v` **deletes the database volume.** Only use it when you
> intend to destroy all data.

### Scaling the workers

```bash
comet up -d --scale queue=3
```

Safe — Redis hands each job to exactly one worker. Do **not** scale `app`
with a fixed `HTTP_PORT` (port conflict); put a load balancer in front first.

---

## Backup and restore

The `db` service mounts `./backups` (i.e. `docker/production/backups/`).

### Back up

```bash
comet exec db sh -c 'pg_dump -U comet_app -Fc comet > /backups/comet-$(date +%F-%H%M).dump'
ls -lh docker/production/backups/
```

Nightly via cron on the host:

```cron
0 2 * * * cd /opt/commet/modern && docker compose -f docker/production/compose.yaml --env-file .env.production exec -T db sh -c 'pg_dump -U comet_app -Fc comet > /backups/comet-$(date +\%F).dump' && find /opt/commet/modern/docker/production/backups -name '*.dump' -mtime +14 -delete
```

### Restore

```bash
comet stop app queue                      # stop writers first
comet exec db pg_restore -U comet_app -d comet --clean --if-exists /backups/<file>.dump
comet start app queue
```

**Also back up `.env.production` and `secrets/pg_app_pw.txt`** — a database
dump is useless without the matching `APP_KEY`.

> Test your restore on a scratch host before you need it. An untested backup
> is not a backup.

---

## Upgrades and rollback

```bash
git pull
comet build
comet up -d          # migrate re-runs, then app/queue roll to the new image
comet logs --tail=50 migrate
curl -f http://localhost:8080/up
```

There is a brief interruption while `app` is recreated. For zero-downtime you
need two app replicas behind a load balancer — out of scope here.

### Rollback

```bash
git checkout <previous-tag>
comet build && comet up -d
```

> **Rolling back code does not roll back a migration.** If the bad release
> changed the schema, restore the pre-upgrade database dump as well. Always
> take a backup *before* upgrading.

---

## TLS / reverse proxy

The stack serves **plain HTTP on 8080**. Never expose that directly to the
internet. Terminate TLS in front of it.

Bind the app to loopback only:

```bash
# .env.production
HTTP_PORT=127.0.0.1:8080
APP_URL=https://comet.example.org
```

Example Caddy config (automatic Let's Encrypt certificates):

```caddy
comet.example.org {
    reverse_proxy 127.0.0.1:8080
}
```

Equivalent nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name comet.example.org;
    ssl_certificate     /etc/letsencrypt/live/comet.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/comet.example.org/privkey.pem;

    client_max_body_size 64m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

The container's nginx already honours `X-Forwarded-*`, so Laravel generates
correct `https://` URLs once the proxy sets them.

---

## Security checklist

Before handling real patient-derived data:

- [ ] `APP_DEBUG=false` (the default here — never override in production)
- [ ] `APP_KEY` generated fresh, stored in a password manager, backed up
- [ ] `secrets/pg_app_pw.txt` is `chmod 600` and not in git
- [ ] `.env.production` is `chmod 600` and not in git
- [ ] TLS terminating in front; `HTTP_PORT` bound to `127.0.0.1`
- [ ] Break-glass password changed from `change-me-now`
- [ ] Entra SSO configured and `AUTH_LOCAL_LOGIN=0`
- [ ] Postgres and Redis ports **not** published to the host (they are not, by default)
- [ ] Nightly backups running, and a restore actually tested
- [ ] Host firewall allows only 80/443
- [ ] `LOG_LEVEL=warning` (avoid `debug` — it can log sensitive values)

`.gitignore` already excludes `.env*`, `secrets/`, and `vocab_data/`. Verify
before your first commit:

```bash
git status --short   # no .env.production, no secrets/, no dumps
```

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `APP_KEY is required` on `up` | not set in `.env.production` | set it; the container refuses to start rather than run with broken crypto |
| `FATAL: APP_KEY is not set` in logs | env file not passed | include `--env-file .env.production` |
| `migrate` shows `exited (0)` | **normal** — one-shot success | nothing to do |
| `migrate` exits non-zero, app never starts | DB unreachable or bad migration | `comet logs migrate` |
| `exec format error` | image built for another CPU arch | rebuild on the target arch or use `--platform` |
| 502 from the reverse proxy | app still booting | `comet logs app`; health has a 30s start period |
| `SQLSTATE[08006] connection refused` | db not healthy yet | `comet ps`; app waits on the healthcheck |
| Vocabulary load finds no files | `VOCAB_DATA_DIR` wrong | it is relative to `docker/production/`; verify with `comet exec app ls /var/www/vocab_data` |
| Jobs queue but never run | worker down | `comet ps queue`, `comet logs queue` |
| Disk full during vocab load | Athena data is large | free space or move the Docker data root |

Collect diagnostics:

```bash
comet ps
comet logs --tail=100 app
comet logs --tail=50 migrate
docker system df                                   # disk usage
comet exec db psql -U comet_app -d comet -c "\dt"  # tables present?
```

---

## Uninstall

```bash
comet down                 # remove containers, KEEP data volumes
comet down -v              # remove containers AND DELETE ALL DATA
```

Back up first — `-v` is irreversible.
