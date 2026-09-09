# COMET

**C**entral **O**nline **M**apping and **E**xport **T**ool — maps PHSA Cerner
source terminology to standard **OMOP CDM v5.4** concepts, with first-class
support for Canadian vocabularies (ICD-10-CA, CCI, SNOMED CT-CA, pCLOCD).

Mappers search the OMOP vocabulary, choose a standard concept for each local
Cerner term, and reviewers approve the result. The output is a
`SOURCE_TO_CONCEPT_MAP` (STCM) — the lookup an ETL uses to turn local codes
into standard concepts when populating an OMOP CDM.

---

## Two apps live here

| Directory | What it is | Status |
|---|---|---|
| **`modern/`** | **Laravel 12 + PostgreSQL 16 rebuild** — Livewire UI, Entra ID SSO, hybrid concept search, CDM v5.4 guardrails, review workflow, vocabulary-drift reporting | **Active — all new work happens here** |
| `public/` | Original procedural PHP 8.2 + MySQL app | Legacy; kept runnable for reference and cutover parity |

The rebuild migrated the legacy data with verified parity, so both can be run
side by side during transition.

---

## Start here

| I want to… | Go to |
|---|---|
| **Deploy to a server** | [`modern/docker/production/README.md`](modern/docker/production/README.md) — Docker Compose, TLS, backups, upgrades |
| Deploy to Azure instead | [`modern/docs/azure-deploy.md`](modern/docs/azure-deploy.md) |
| Set up locally / resume on a new machine | [`RESUME.md`](RESUME.md) |
| Understand the app | [`modern/README.md`](modern/README.md) |
| Load an OMOP vocabulary | [`modern/docs/vocab-refresh.md`](modern/docs/vocab-refresh.md) |
| Map terms (day-to-day) | [`modern/docs/mapper-guide.md`](modern/docs/mapper-guide.md) |
| Review submitted maps | [`modern/docs/reviewer-guide.md`](modern/docs/reviewer-guide.md) |
| Read the background theory | [`modern/docs/COMET-chapter.pdf`](modern/docs/COMET-chapter.pdf) |

## Deploy, in short

On a server with Docker and Compose v2 installed:

```bash
sudo mkdir -p /opt/comet && sudo chown "$USER:$USER" /opt/comet
git clone https://github.com/melsiddieg/phsa-comet.git /opt/comet
cd /opt/comet/modern

mkdir -p ../secrets                                # git-ignored, not in the clone
openssl rand -hex 24 > ../secrets/pg_app_pw.txt    # database password
chmod 600 ../secrets/pg_app_pw.txt

cp docker/production/.env.production.example .env.production
echo "APP_KEY=base64:$(openssl rand -base64 32)"   # paste into the file
$EDITOR .env.production                            # APP_KEY, APP_URL, COMET_DOMAIN, ACME_EMAIL

docker compose -f docker/production/compose.yaml \
               -f docker/production/compose.caddy.yaml \
               --env-file .env.production up -d --build
```

One image runs three roles — `app` (nginx + php-fpm), `queue` (worker), and
`migrate` (one-shot, so migrations never race) — behind Caddy, which handles
Let's Encrypt certificates automatically. The
[full guide](modern/docker/production/README.md) covers firewall, systemd,
backups and upgrades.

## How it fits the OHDSI ecosystem

COMET sits where **Usagi** does — machine proposes candidates, a human
decides — but as a multi-user web app with CDM v5.4 guardrails, an approval
workflow, an audit trail, and vocabulary-drift detection. It reads the
standard vocabularies from **OHDSI Athena** and exports STCM (and
Usagi-format) files, so it drops into an existing OHDSI pipeline. It can
co-host with **Broadsea** on one server — see
[Alternative environments](modern/docker/production/README.md#alternative-environments).

## Data that is never committed

`.gitignore` excludes, and these must be supplied per environment:
`secrets/`, `.env*`, the legacy MySQL dump (`db/init/`), Cerner extracts
(`public/mr_data/`), Athena downloads (`vocab_data/`), and database backups.
See [`RESUME.md`](RESUME.md) for how to obtain each.
