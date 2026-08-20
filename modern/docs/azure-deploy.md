# Deploying COMET to Azure

The production image (`docker/production/Dockerfile`) is fully self-contained:
code baked in, nginx + php-fpm supervised, listening on **:8080**, health at
**`/up`**. One image serves two roles via `CONTAINER_ROLE`:

| Role | What runs | Azure shape |
|------|-----------|-------------|
| `app` (default) | nginx + php-fpm | Container App with HTTP ingress |
| `queue` | `php artisan queue:work` | Container App, **no ingress**, min replicas 1 |

## Build & test locally

```bash
cd modern
podman build -f docker/production/Dockerfile -t comet:latest .

# Smoke test (no DB needed for boot; /up returns 200)
podman run --rm -p 8080:8080 \
  -e APP_KEY="$(podman run --rm comet:latest php artisan key:generate --show)" \
  -e APP_ENV=production -e APP_DEBUG=false \
  -e CACHE_STORE=file -e SESSION_DRIVER=file -e QUEUE_CONNECTION=sync \
  comet:latest
curl -f http://localhost:8080/up
```

## Azure resources (one-time)

```bash
RG=comet-rg           LOC=canadacentral
ACR=cometacr          ENV=comet-env
PG=comet-pg           REDIS=comet-redis

az group create -n $RG -l $LOC

# 1. Container registry
az acr create -n $ACR -g $RG --sku Basic
az acr login -n $ACR

# 2. PostgreSQL Flexible Server (16) — private access or firewall as policy dictates
az postgres flexible-server create -n $PG -g $RG -l $LOC \
  --version 16 --tier Burstable --sku-name Standard_B2s \
  --storage-size 128 --database-name comet

# 3. Azure Cache for Redis (TLS-only, port 6380)
az redis create -n $REDIS -g $RG -l $LOC --sku Basic --vm-size c1

# 4. Container Apps environment
az containerapp env create -n $ENV -g $RG -l $LOC
```

> **Sizing note:** a full Athena vocabulary load needs tens of GB in
> Postgres — size `--storage-size` accordingly (128 GB+ recommended).

## Push the image to ACR

> ### ⚠️ Build for `linux/amd64`
>
> **Azure Container Apps runs amd64 only.** On an Apple Silicon Mac (or any
> arm64 host) a plain `podman build` produces an **arm64** image that pushes
> fine but crashes on start in Azure with `exec format error`. Always pass
> `--platform linux/amd64`. The GitHub Actions runner is already amd64, so
> CI builds are unaffected — this only bites local pushes.

### 1. Log in

```bash
az login
az acr login --name $ACR          # writes a token into your local Docker/Podman config
```

If `az acr login` can't reach the local container runtime (common with
Podman), authenticate directly instead:

```bash
TOKEN=$(az acr login --name $ACR --expose-token --query accessToken -o tsv)
podman login $ACR.azurecr.io -u 00000000-0000-0000-0000-000000000000 -p "$TOKEN"
```

### 2. Build for the right architecture and tag

Tag with the **git SHA**, not just `latest` — Container Apps needs a
changing tag to create a new revision, and it makes rollback trivial.

```bash
cd modern
TAG=$(git rev-parse --short HEAD)

podman build --platform linux/amd64 \
  -f docker/production/Dockerfile \
  -t $ACR.azurecr.io/comet:$TAG \
  -t $ACR.azurecr.io/comet:latest .
```

### 3. Push

```bash
podman push $ACR.azurecr.io/comet:$TAG
podman push $ACR.azurecr.io/comet:latest
```

### 4. Verify what landed

Confirm the tag exists **and** that the architecture is `amd64`:

```bash
az acr repository show-tags -n $ACR --repository comet -o table

az acr manifest list-metadata -r $ACR -n comet \
  --query "[?tags[?@=='$TAG']].{tag:tags[0],arch:architecture,os:os}" -o table
# arch must read: amd64
```

### Alternative: build in Azure (no local Docker, always amd64)

`az acr build` uploads the build context and builds server-side — this
sidesteps the architecture problem entirely and is the easiest path from a
Mac:

```bash
cd modern
az acr build --registry $ACR --platform linux/amd64 \
  --image comet:$(git rev-parse --short HEAD) \
  --image comet:latest \
  --file docker/production/Dockerfile .
```

## Let Container Apps pull from ACR

The apps need permission to pull. Prefer **managed identity** over the
registry admin account — no credentials to store or rotate.

```bash
# 1. Give each app a system-assigned identity
az containerapp identity assign -n comet-app   -g $RG --system-assigned
az containerapp identity assign -n comet-queue -g $RG --system-assigned

# 2. Grant that identity AcrPull on the registry
ACR_ID=$(az acr show -n $ACR -g $RG --query id -o tsv)
for APP in comet-app comet-queue; do
  PRINCIPAL=$(az containerapp show -n $APP -g $RG --query identity.principalId -o tsv)
  az role assignment create --assignee "$PRINCIPAL" --role AcrPull --scope "$ACR_ID"
done

# 3. Point the app at the registry using that identity
az containerapp registry set -n comet-app   -g $RG --server $ACR.azurecr.io --identity system
az containerapp registry set -n comet-queue -g $RG --server $ACR.azurecr.io --identity system
```

<details>
<summary>Fallback: admin credentials (simpler, less secure — avoid for PHI)</summary>

```bash
az acr update -n $ACR --admin-enabled true
az containerapp registry set -n comet-app -g $RG \
  --server $ACR.azurecr.io \
  --username $(az acr credential show -n $ACR --query username -o tsv) \
  --password $(az acr credential show -n $ACR --query 'passwords[0].value' -o tsv)
```
</details>

> **Chicken-and-egg:** identity assignment requires the app to exist. On a
> first-time deploy, create the apps with admin credentials (or
> `--registry-identity system`), then switch to managed identity as above.

## Environment variables

| Variable | Value | Notes |
|----------|-------|-------|
| `APP_KEY` | `base64:…` | **secret** — generate once, reuse across replicas |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://<your-fqdn>` | |
| `DB_HOST` | `$PG.postgres.database.azure.com` | |
| `DB_DATABASE` | `comet` | |
| `DB_USERNAME` | flexible-server admin (or app user) | |
| `DB_PASSWORD` | **secret** | read before the `/run/secrets` fallback |
| `DB_SSLMODE` | `require` | Azure PG enforces TLS |
| `REDIS_URL` | `rediss://:<access-key>@$REDIS.redis.cache.windows.net:6380` | TLS port 6380 |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` | |
| `SESSION_DRIVER` | `database` | |
| `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` / `ENTRA_TENANT_ID` | from app registration | secret; set redirect URI to `https://<fqdn>/auth/callback` |
| `AUTH_LOCAL_LOGIN` | `0` | disable break-glass once SSO works |
| `RUN_MIGRATIONS` | `1` on the **app** container only | avoids concurrent migrations from the queue app |

## Deploy from ACR — first time

Set the values once, then paste the two commands.

```bash
TAG=$(git rev-parse --short HEAD)
IMAGE=$ACR.azurecr.io/comet:$TAG

# Generate ONE app key and keep it — changing it invalidates all sessions
# and any encrypted column. (Same format as `artisan key:generate`, but no
# container needed — handy since the pushed image is amd64.)
APP_KEY="base64:$(openssl rand -base64 32)"

DB_HOST=$PG.postgres.database.azure.com
DB_USER='cometadmin'
DB_PASS='<the-postgres-password>'
REDIS_KEY=$(az redis list-keys -n $REDIS -g $RG --query primaryKey -o tsv)
REDIS_URL="rediss://:$REDIS_KEY@$REDIS.redis.cache.windows.net:6380"
```

**Web app** — external ingress on 8080, owns migrations:

```bash
az containerapp create -n comet-app -g $RG --environment $ENV \
  --image "$IMAGE" \
  --registry-server $ACR.azurecr.io --registry-identity system \
  --target-port 8080 --ingress external \
  --min-replicas 1 --max-replicas 3 \
  --cpu 1 --memory 2Gi \
  --secrets app-key="$APP_KEY" db-password="$DB_PASS" redis-url="$REDIS_URL" \
  --env-vars \
    CONTAINER_ROLE=app RUN_MIGRATIONS=1 \
    APP_ENV=production APP_DEBUG=false \
    APP_KEY=secretref:app-key \
    DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT=5432 \
    DB_DATABASE=comet DB_USERNAME="$DB_USER" \
    DB_PASSWORD=secretref:db-password DB_SSLMODE=require \
    REDIS_URL=secretref:redis-url \
    CACHE_STORE=redis QUEUE_CONNECTION=redis SESSION_DRIVER=database
```

**Queue worker** — same image, no ingress, never migrates:

```bash
az containerapp create -n comet-queue -g $RG --environment $ENV \
  --image "$IMAGE" \
  --registry-server $ACR.azurecr.io --registry-identity system \
  --min-replicas 1 --max-replicas 2 \
  --cpu 1 --memory 2Gi \
  --secrets app-key="$APP_KEY" db-password="$DB_PASS" redis-url="$REDIS_URL" \
  --env-vars \
    CONTAINER_ROLE=queue RUN_MIGRATIONS=0 \
    APP_ENV=production APP_DEBUG=false \
    APP_KEY=secretref:app-key \
    DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT=5432 \
    DB_DATABASE=comet DB_USERNAME="$DB_USER" \
    DB_PASSWORD=secretref:db-password DB_SSLMODE=require \
    REDIS_URL=secretref:redis-url \
    CACHE_STORE=redis QUEUE_CONNECTION=redis
```

Then set `APP_URL` to the real hostname and confirm it's up:

```bash
FQDN=$(az containerapp show -n comet-app -g $RG \
  --query properties.configuration.ingress.fqdn -o tsv)
az containerapp update -n comet-app -g $RG --set-env-vars APP_URL="https://$FQDN"

curl -f https://$FQDN/up && echo "  ✅ COMET is live at https://$FQDN"
```

## Redeploy a new version

Push a new tag, then point both apps at it. Container Apps creates a new
revision and shifts traffic once it passes its probes.

```bash
TAG=$(git rev-parse --short HEAD)      # after pushing this tag to ACR

az containerapp update -n comet-app   -g $RG --image $ACR.azurecr.io/comet:$TAG
az containerapp update -n comet-queue -g $RG --image $ACR.azurecr.io/comet:$TAG
```

Update the **web app first** — it runs the migrations — then the worker.
(The GitHub Actions workflow does this ordering automatically and waits for
health in between.)

## Roll back

Revisions are immutable, so rollback is just reactivating the previous one:

```bash
az containerapp revision list -n comet-app -g $RG \
  --query "[].{rev:name,image:properties.template.containers[0].image,active:properties.active,health:properties.healthState}" -o table

az containerapp revision activate -n comet-app -g $RG --revision <previous-revision>
```

> Rolling back **code** does not roll back a **migration**. If the bad
> release migrated the schema, restore from a Postgres backup or ship a
> corrective migration — don't assume revision rollback undoes it.

## Troubleshooting

```bash
# Live logs
az containerapp logs show -n comet-app -g $RG --follow

# Startup/system events (image pull failures show up here)
az containerapp logs show -n comet-app -g $RG --type system
```

| Symptom | Cause | Fix |
|---------|-------|-----|
| `exec format error` | arm64 image on amd64 host | rebuild with `--platform linux/amd64` |
| `UNAUTHORIZED` / image pull fails | app identity lacks `AcrPull` | re-run the role assignment above |
| Boots then exits, `FATAL: APP_KEY is not set` | secret not wired | check `--secrets app-key=…` **and** `APP_KEY=secretref:app-key` |
| Health probe fails, no app logs | container not listening on 8080 | confirm `--target-port 8080` |
| `SQLSTATE[08006] … SSL` | TLS not requested | set `DB_SSLMODE=require` |

Seed the break-glass admin once (then rotate/disable it after Entra works):

```bash
az containerapp exec -n comet-app -g $RG --command "php artisan db:seed --force"
```

## Loading vocabulary in Azure

`comet:load-vocab` reads CSVs from a path visible to the *app container*.
Mount an Azure Files share into both the app container (upload target) and
run the artisan command there, or run the load from a temporary jumpbox
container in the same environment. The staged-swap design means the app
stays live during the load.

## Upgrades

New code → build a new tag → `az containerapp update --image …` on both
apps. Config/route/view caches are rebuilt at container start; opcache never
revalidates (code is immutable per image).

## Automated deploys (GitHub Actions)

`.github/workflows/deploy-azure.yml` runs tests → builds → pushes to ACR →
updates both Container Apps → smoke-tests `/up`. It triggers on **release
tags** (`v*`) or manual dispatch — never on an ordinary push to `main`.

Migrations run from the **web app only** (`RUN_MIGRATIONS=1`), and the queue
worker is updated only after the web app reports healthy, so a bad image is
caught before it reaches the workers.

### One-time: OIDC federated credentials (no stored passwords)

```bash
APP_ID=$(az ad app create --display-name comet-github-deploy --query appId -o tsv)
az ad sp create --id "$APP_ID"
SUB=$(az account show --query id -o tsv)

# Let the workflow push images and update Container Apps
az role assignment create --assignee "$APP_ID" --role AcrPush \
  --scope /subscriptions/$SUB/resourceGroups/$RG/providers/Microsoft.ContainerRegistry/registries/$ACR
az role assignment create --assignee "$APP_ID" --role Contributor \
  --scope /subscriptions/$SUB/resourceGroups/$RG

# Trust GitHub's OIDC token for this repo — one credential per trigger type
az ad app federated-credential create --id "$APP_ID" --parameters '{
  "name": "github-tags",
  "issuer": "https://token.actions.githubusercontent.com",
  "subject": "repo:melsiddieg/phsa-commet:ref:refs/tags/v1",
  "audiences": ["api://AzureADTokenExchange"]
}'
az ad app federated-credential create --id "$APP_ID" --parameters '{
  "name": "github-production",
  "issuer": "https://token.actions.githubusercontent.com",
  "subject": "repo:melsiddieg/phsa-commet:environment:production",
  "audiences": ["api://AzureADTokenExchange"]
}'
```

> The `ref:refs/tags/v1` subject must match the tags you actually push. For
> arbitrary `v*` tags, add a credential with a wildcard-capable
> `claims_matching_expression`, or rely on the `environment:production`
> credential and always deploy through the `production` environment.

### Repository configuration

**Secrets** (Settings → Secrets and variables → Actions → *Secrets*):

| Secret | Value |
|--------|-------|
| `AZURE_CLIENT_ID` | `$APP_ID` from above |
| `AZURE_TENANT_ID` | `az account show --query tenantId -o tsv` |
| `AZURE_SUBSCRIPTION_ID` | `az account show --query id -o tsv` |

**Variables** (same page → *Variables*):

| Variable | Example |
|----------|---------|
| `ACR_NAME` | `cometacr` |
| `AZURE_RESOURCE_GROUP` | `comet-rg` |
| `AZURE_APP_NAME` | `comet-app` |
| `AZURE_QUEUE_NAME` | `comet-queue` |

**Environment**: create one named `production` (Settings → Environments) and
add required reviewers — the `deploy` job then waits for human approval
before touching Azure.

Application secrets (`APP_KEY`, `DB_PASSWORD`, `REDIS_URL`, Entra creds) are
**not** in GitHub — they live as Container App secrets and are set once at
`az containerapp create` time. The workflow only swaps the image tag.

## What deliberately stays out of the image

- `.env` files and every secret (supplied via Container Apps secrets)
- `vocab_data/`, `mr_data/`, DB dumps (git-ignored data; never bake in)
- dev-only services (the compose Postgres/Redis are replaced by managed Azure services)
