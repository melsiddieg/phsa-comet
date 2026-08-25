# COMET — Production Deployment (Docker Compose)

Manual, self-hosted deployment of COMET onto a **single standalone Linux VM**
using Docker Compose. Everything runs on that one host: app, queue worker,
PostgreSQL, Redis, and (optionally) a TLS-terminating proxy.

Written against **Ubuntu 20.04 LTS** and **Docker Compose v2.26.1**
(`docker compose` — with a space, not the legacy `docker-compose`). The steps
work unchanged on 22.04/24.04 and, with the noted package swap, on RHEL 9.

**Whole deployment, end to end:**

| # | Step | Time |
|---|------|------|
| 1 | [Prepare the VM](#prepare-the-vm) — Docker, firewall, disk | ~10 min |
| 2 | [Quick start](#quick-start) — secrets, env, build, first admin | ~15 min |
| 3 | [TLS and port 80](#tls-and-port-80) — proxy / certificates | ~5 min |
| 4 | [Run at boot](#run-at-boot-systemd) — systemd unit, reboot test | ~5 min |
| 5 | [Loading data](#loading-data) — vocabulary and source terms | hours |
| 6 | [Security checklist](#security-checklist) — before real data | ~10 min |

Budget about an hour to a working, TLS-protected instance, plus vocabulary
load time.

---

## Contents

1. [Architecture](#architecture)
2. [Requirements](#requirements)
3. [Prepare the VM](#prepare-the-vm)
4. [Quick start](#quick-start)
5. [TLS and port 80](#tls-and-port-80)
6. [Run at boot (systemd)](#run-at-boot-systemd)
7. [Configuration](#configuration)
8. [Loading data](#loading-data)
9. [Day-2 operations](#day-2-operations)
10. [Backup and restore](#backup-and-restore)
11. [Upgrades and rollback](#upgrades-and-rollback)
12. [Security checklist](#security-checklist)
13. [Troubleshooting](#troubleshooting)

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

## Prepare the VM

A fresh Linux VM needs Docker Engine, the Compose plugin, and a firewall.
These steps assume `sudo` and a non-root login user.

> **Already have Docker?** Check what you have and skip ahead:
>
> ```bash
> docker compose version    # want v2.26.1+
> docker --version          # want 20.10+ (24.0+ preferred)
> docker run --rm hello-world
> ```
>
> If Compose reports **v2.26.1** or newer, jump straight to
> [Quick start](#quick-start) — but still do the
> [Firewall](#firewall) and [Disk](#disk) steps below, which are easy to
> miss and matter more than the install.
>
> Both compose files in this directory are validated against **v2.26.1**
> exactly: they parse with zero warnings and use no feature newer than that
> release.

### Ubuntu 20.04 LTS (focal)

> **Check your Ubuntu release first — 20.04 reached end of standard support in
> April 2025.** It only receives security updates under an Ubuntu Pro / ESM
> subscription. Running an unpatched OS under a PHI workload is a real finding
> in any security review. Confirm ESM is attached, or plan an upgrade to 22.04
> / 24.04:
>
> ```bash
> lsb_release -a          # confirm: Ubuntu 20.04.x LTS (focal)
> pro status              # "esm-infra: enabled" if covered
> ```
>
> The commands below work unchanged on 22.04 and 24.04 — `$VERSION_CODENAME`
> selects the right Docker repo automatically.

**Remove any old Docker first.** 20.04's archive ships `docker.io` and the
Python-based `docker-compose` v1, which conflict with the modern packages.
This stack needs Compose **v2** (`docker compose`, with a space):

```bash
sudo apt-get remove -y docker docker-engine docker.io containerd runc docker-compose
```

```bash
# 1. Docker Engine + Compose plugin, from Docker's own repo (focal's archive
#    has no Compose v2 at all)
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings   # does not exist on 20.04 by default
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io \
                        docker-buildx-plugin docker-compose-plugin

# 2. Run docker without sudo (log out and back in for this to take effect)
sudo usermod -aG docker "$USER"

# 3. Start on boot
sudo systemctl enable --now docker
```

### RHEL 9 / Rocky / AlmaLinux (alternative)

```bash
sudo dnf -y install dnf-plugins-core
sudo dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
sudo dnf -y install docker-ce docker-ce-cli containerd.io \
                    docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker "$USER"
sudo systemctl enable --now docker
```

### Verify

```bash
docker --version          # 24.0+
docker compose version    # v2.26.1+
docker run --rm hello-world
```

If `docker compose version` errors but `docker-compose --version` prints
`1.x`, you are still on the old Python Compose — the plugin did not install.
Re-run the `docker-compose-plugin` step above. Every command in this README
uses `docker compose` (space); Compose v1 will not understand this file.

### Firewall

Only 80/443 should reach the VM. Postgres and Redis are **not** published to
the host by default — do not open 5432 or 6379.

```bash
# Ubuntu / Debian
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# RHEL family
sudo firewall-cmd --permanent --add-service=ssh
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload
```

> **Docker bypasses ufw.** Docker writes its own iptables rules, so a
> published port is reachable even if ufw "denies" it. That is exactly why
> this stack keeps the database and cache unpublished and binds the app to
> `127.0.0.1` behind a proxy — do not rely on ufw alone to hide a service.

### Disk

The vocabulary is the space hog. Docker stores volumes under
`/var/lib/docker`, so that filesystem needs the room:

```bash
df -h /var/lib/docker
```

If your root disk is small but a data disk is mounted elsewhere, move
Docker's data root before you load anything:

```bash
sudo systemctl stop docker
echo '{ "data-root": "/mnt/data/docker" }' | sudo tee /etc/docker/daemon.json
sudo rsync -aP /var/lib/docker/ /mnt/data/docker/
sudo systemctl start docker
```

### Where to put the code

The systemd unit shipped here assumes **`/opt/comet`**:

```bash
sudo mkdir -p /opt/comet
sudo chown "$USER:$USER" /opt/comet
```

---

## Quick start

### 1. Get the code

```bash
cd /opt/comet
git clone https://github.com/melsiddieg/phsa-comet.git .
cd modern
```

The Compose project name is pinned to `comet` inside `compose.yaml`, so
container and volume names do not depend on this directory.

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

## TLS and port 80

The stack serves **plain HTTP on 8080** and must sit behind something that
terminates TLS. Which option you want depends entirely on what already owns
port 80 on this VM. Find out first:

```bash
sudo ss -lptn 'sport = :80'                  # what is listening
docker ps --format '{{.Names}}\t{{.Ports}}'   # is it a container?
```

| What you find | Use | Section |
|---|---|---|
| Nothing on 80/443 | bundled Caddy, automatic certs | [A](#a-nothing-else-on-80443--bundled-caddy) |
| **Another _container_ owns 80** (Traefik, nginx-proxy, another stack) | share its network, publish no ports | [B](#b-another-container-already-owns-80) |
| **OHDSI Broadsea 3** owns 80 (its Traefik) | label-based routing, dedicated hostname | [B special case](#b-special-case-alongside-ohdsi-broadsea-3) |
| A **host** nginx/Apache owns 80 | bind to loopback, `proxy_pass` | [C](#c-a-host-nginx--apache-owns-80) |
| The VM has a **second IP** free | bind Caddy to that IP | [D](#d-a-spare-ip-is-available) |

Whichever you pick, set the public URL:

```bash
# .env.production
APP_URL=https://comet.example.org
```

---

### A. Nothing else on 80/443 — bundled Caddy

Caddy obtains and renews Let's Encrypt certificates automatically.

**1. Point DNS at the VM** and verify *before* starting — a failed ACME
challenge counts against Let's Encrypt rate limits:

```bash
dig +short comet.example.org      # must print this VM's public IP
```

**2. Configure** in `.env.production`:

```bash
COMET_DOMAIN=comet.example.org
ACME_EMAIL=ops@example.org
HTTP_PORT=127.0.0.1:8080      # stop publishing the app publicly
```

**3. Start with the overlay:**

```bash
docker compose \
  -f docker/production/compose.yaml \
  -f docker/production/compose.caddy.yaml \
  --env-file .env.production up -d
```

> Certificates live in the `caddy_data` volume. **Do not delete it** —
> re-issuing repeatedly hits Let's Encrypt rate limits (5 duplicate
> certificates per week).

---

### B. Another container already owns 80

This is the common case on a shared VM. Do **not** use the Caddy overlay —
it would fight for port 80. Instead COMET publishes **no host ports at all**
and the existing proxy reaches it over a shared Docker network.

**1. Find the proxy's network:**

```bash
docker ps                                  # identify the proxy container
docker inspect -f '{{range $n,$_ := .NetworkSettings.Networks}}{{$n}} {{end}}' <proxy-container>
```

Typical names: `web`, `proxy`, `traefik_default`, `nginx-proxy_default`.

**2. Configure** in `.env.production`:

```bash
PROXY_NETWORK=web                      # the network you just found
COMET_DOMAIN=comet.example.org
APP_URL=https://comet.example.org
```

**3. Start with the proxy overlay:**

```bash
docker compose \
  -f docker/production/compose.yaml \
  -f docker/production/compose.proxy.yaml \
  --env-file .env.production up -d
```

COMET is now reachable **only** from that network, as host `app` port `8080`.

**4. Tell the existing proxy about it.** How depends on which proxy it is —
`compose.proxy.yaml` has ready-made label blocks for Traefik and
nginx-proxy; uncomment the one that matches. For a plain Caddy or nginx
container, add a route pointing at `app:8080`:

```caddy
# existing Caddy container's Caddyfile
comet.example.org {
    reverse_proxy app:8080
}
```

```nginx
# existing nginx container's config
location / {
    proxy_pass http://app:8080;
    proxy_set_header Host              $host;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

> The proxy container must be attached to `PROXY_NETWORK` too — that is how
> it resolves the name `app`. If it cannot, `docker network connect
> <network> <proxy-container>` fixes it.
>
> **Name collision:** the service is called `app`, which is generic. If the
> other stack also has an `app` on that network, DNS is ambiguous — add a
> network alias (e.g. `comet-app`) and point the proxy at that instead.

---

### B (special case). Alongside OHDSI Broadsea 3

Broadsea runs **Traefik v2.11** (container `traefik`) bound to 80/443. Its
Traefik enables *both* providers — the Docker socket **and** the static file
`traefik/routers.yml`. Broadsea's own services use the file provider; we use
the **Docker provider via labels**, so nothing in your Broadsea checkout is
edited and a Broadsea upgrade cannot clobber COMET's routing.

> #### Use a dedicated hostname, not a path prefix
>
> Broadsea routes everything by path on one host — `/atlas`, `/WebAPI`,
> `/hades`, with `broadsea-content` claiming `PathPrefix(`/`)` as a
> catch-all. Serving COMET at `/comet` would need `stripPrefix`, and that
> **breaks the UI**: Livewire posts to `/livewire/update` at the *root*, so
> after stripping, the browser's request goes to `/` — which Traefik hands
> to `broadsea-content`, not COMET. Every interactive screen dies.
>
> Give COMET its own hostname (`comet.example.org`, or a CNAME to the same
> VM). That is a one-line DNS change and avoids the whole class of problem.

**1. Confirm Broadsea's network name** (it is the project's default network,
so it follows the directory Broadsea was cloned into):

```bash
docker inspect -f '{{range $n,$_ := .NetworkSettings.Networks}}{{$n}}{{"\n"}}{{end}}' traefik
# typically: broadsea_default
```

**2. Confirm which entrypoint Broadsea uses** — it is named after
`HTTP_TYPE` in Broadsea's `.env` (`http` or `https`):

```bash
grep -E '^HTTP_TYPE' /path/to/Broadsea/.env
```

**3. Configure** in `.env.production`:

```bash
PROXY_NETWORK=broadsea_default
COMET_DOMAIN=comet.example.org
COMET_ENTRYPOINT=http            # must match Broadsea's HTTP_TYPE
APP_URL=http://comet.example.org # https:// if Broadsea terminates TLS
```

**4. Start with the Broadsea overlay:**

```bash
docker compose \
  -f docker/production/compose.yaml \
  -f docker/production/compose.broadsea.yaml \
  --env-file .env.production up -d
```

COMET publishes **no host ports**. Traefik discovers it by label and routes
`Host(comet.example.org)` to `app:8080` over Broadsea's network. The app also
gets the network alias `comet-app`, since `app` is generic on a shared
network.

**5. Verify:**

```bash
# Traefik should list a router called "comet"
curl -s http://<broadsea-host>/api/http/routers | grep -o '"name":"comet[^"]*"'

curl -I http://comet.example.org/up      # expect 200
```

> **TLS:** COMET inherits whatever Broadsea does. If Broadsea runs
> `HTTP_TYPE=https`, its Traefik needs a certificate valid for
> `comet.example.org` too — a SAN/wildcard cert, or an added cert entry in
> Broadsea's `traefik/tls_https.yml`. If Broadsea is HTTP-only, COMET is
> HTTP-only, which is **not acceptable for real data** — fix that at the
> Broadsea layer so both benefit.

**Why they belong together:** COMET produces the `SOURCE_TO_CONCEPT_MAP` that
drives the ETL populating the OMOP CDM — the same CDM that Broadsea's ATLAS
and WebAPI query. Mapping and analysis on one host is a coherent setup, not a
coincidence.

---

### C. A host nginx / Apache owns 80

Keep the app on loopback and proxy to it from the host:

```bash
# .env.production
HTTP_PORT=127.0.0.1:8080
```

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
        proxy_read_timeout 300s;
    }
}
```

---

### D. A spare IP is available

If the VM has a second address, give Caddy that one and leave the existing
service on the primary. No overlay changes needed — `HTTP_PORT` and the
Caddy ports both accept an IP prefix:

```bash
# .env.production
HTTP_PORT=127.0.0.1:8080
```

Then bind Caddy's ports to the spare IP by adding a tiny third file:

```yaml
# docker/production/compose.caddy-ip.yaml
services:
  caddy:
    ports: !override
      - "10.0.0.5:80:80"
      - "10.0.0.5:443:443"
```

```bash
docker compose -f docker/production/compose.yaml \
               -f docker/production/compose.caddy.yaml \
               -f docker/production/compose.caddy-ip.yaml \
               --env-file .env.production up -d
```

---

### Whichever you chose

The container's nginx already honours `X-Forwarded-*`, so Laravel emits
correct `https://` links once the proxy sets them. Verify end to end:

```bash
curl -I https://comet.example.org/up      # expect 200
```

Fold the extra `-f` files into your alias so later commands keep working:

```bash
alias comet='docker compose -f docker/production/compose.yaml -f docker/production/compose.proxy.yaml --env-file .env.production'
```

---

## Run at boot (systemd)

Services carry `restart: unless-stopped`, so **Docker already restarts them
after a reboot**. The unit below adds ordered start/stop, recovers from a
`docker compose down`, and gives you `systemctl status comet`.

```bash
sudo cp docker/production/comet.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now comet
systemctl status comet --no-pager
```

The unit assumes the repo is at **`/opt/comet`**. If you cloned elsewhere,
edit `WorkingDirectory=` before enabling it. To include the TLS overlay, add
`-f docker/production/compose.caddy.yaml` to the `ExecStart`/`ExecStop` lines.

Verify it really survives a reboot — do this once, before go-live:

```bash
sudo reboot
# ... reconnect ...
systemctl is-active comet && curl -f https://comet.example.org/up
```

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
0 2 * * * cd /opt/comet/modern && docker compose -f docker/production/compose.yaml --env-file .env.production exec -T db sh -c 'pg_dump -U comet_app -Fc comet > /backups/comet-$(date +\%F).dump' && find /opt/comet/modern/docker/production/backups -name '*.dump' -mtime +14 -delete
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
- [ ] **Host OS receiving security updates** — on Ubuntu 20.04 that means an
      active Ubuntu Pro/ESM subscription (`pro status`), or an upgrade to a
      supported release
- [ ] `unattended-upgrades` enabled, or a documented patching schedule

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
| `docker compose` → "is not a docker command" | Compose v2 plugin missing (common on 20.04) | install `docker-compose-plugin`; v1 `docker-compose` will not work |
| `permission denied ... docker.sock` | user not in the `docker` group | `sudo usermod -aG docker $USER`, then log out and back in |
| Caddy: "no acme server" / challenge fails | DNS not pointing at the VM, or 80/443 blocked | `dig +short $COMET_DOMAIN`; open 80 **and** 443 |
| `port is already allocated` on 80/443 | something else owns the port | you want option **B**, not the Caddy overlay — see [TLS and port 80](#tls-and-port-80) |
| `network <name> declared as external, but could not be found` | wrong `PROXY_NETWORK` | `docker network ls`; use the proxy's actual network |
| Proxy returns 502; `host not found in upstream "app"` | proxy not attached to the shared network | `docker network connect $PROXY_NETWORK <proxy-container>` |
| Proxy reaches the *wrong* app | another service on that network is also named `app` | add a network alias for COMET and target that |
| Broadsea/Traefik never routes to COMET | `traefik.enable` missing, or wrong entrypoint | Traefik runs `exposedByDefault:false`; check `COMET_ENTRYPOINT` matches Broadsea's `HTTP_TYPE` |
| Traefik 404s on the COMET hostname | DNS/host header not matching `COMET_DOMAIN` | `curl -H 'Host: comet.example.org' http://<vm-ip>/up` to test past DNS |
| COMET loads but buttons/tables do nothing | served under a **path prefix** — Livewire posts to `/livewire/update` at the root | use a dedicated hostname, not `/comet` |
| Caddy loops re-issuing certificates | `caddy_data` volume was deleted | restore/keep the volume; Let's Encrypt rate-limits duplicates |
| Site loads over HTTP but links say `http://` | `APP_URL` still `http://`, or proxy not sending `X-Forwarded-Proto` | fix `APP_URL`, then `comet up -d` |
| `systemctl status comet` fails after reboot | wrong `WorkingDirectory` in the unit | edit `/etc/systemd/system/comet.service`, `daemon-reload` |

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
