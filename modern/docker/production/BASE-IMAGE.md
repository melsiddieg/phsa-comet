# Base image: Debian vs Alpine

This branch (`try/debian-base`) swaps the production image from Alpine to
**Debian bookworm** (`php:8.4-cli` / `php:8.4-fpm`). Behaviour is identical;
the only reason is **package-mirror reachability**.

## Why

On the target server, Alpine's mirror is unreachable in both directions:

| Attempt | Result |
|---|---|
| `https://dl-cdn.alpinelinux.org` | `TLS: unspecified error` (interception, cert untrusted) |
| `http://dl-cdn.alpinelinux.org` (`APK_HTTP=1`) | `HTTP 503: Service Unavailable` |

Debian's mirrors are entirely different hosts, so they may be allowed where
Alpine's are not.

## Check before building

Takes seconds and tells you whether this branch helps:

```bash
docker run --rm debian:bookworm-slim sh -c 'apt-get update >/dev/null && echo MIRROR-OK'
```

- `MIRROR-OK` → this branch will build; carry on as normal.
- Errors → Debian is blocked too; use the prebuilt-image route (Fix 5 in the
  deployment README) rather than switching bases.

## What changed

| | Alpine | Debian |
|---|---|---|
| Base | `php:8.4-*-alpine` | `php:8.4-cli` / `php:8.4-fpm` |
| Packages | `apk` → `dl-cdn.alpinelinux.org` | `apt` → `deb.debian.org` |
| `icu-dev` etc. | `icu-dev libzip-dev postgresql-dev` | `libicu-dev libzip-dev libpq-dev` |
| Corporate CA | append to bundle + `SSL_CERT_FILE` (apk-tools 3 quirk) | `update-ca-certificates` (standard) |
| `APK_HTTP` escape hatch | present | **removed** — not applicable |
| Image size | ~660 MB | ~657 MB |

Build-only packages are removed afterwards using the upstream `docker-php`
`apt-mark` pattern, which is why the image did not grow.

Everything else — roles, entrypoint, nginx, supervisord, ports, healthcheck —
is unchanged, so `compose.yaml` and every overlay work as-is.

## Verified

Built and smoke-tested on this branch:

- `/up` → 200, `/login` → 200 (`COMET — Sign in`)
- extensions loaded: `intl pcntl pdo_pgsql pgsql redis zip` + Zend OPcache
- opcache **live in FPM** with our overrides (`validate_timestamps => Off`,
  `memory_consumption => 192`)
- zero errors/CRIT in container logs

## Merging

If `MIRROR-OK` passes on the server, merge this branch to `main`. If Debian is
blocked too, abandon it — the prebuilt-image path works regardless of base.
