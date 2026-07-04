#!/usr/bin/env bash
set -euo pipefail

# 1. Folder tree
mkdir -p docker/nginx docker/php db/conf.d db/init secrets sql

# 2. Template .env
cat > .env.example <<'EOF'
# Copy to .env and adjust
MYSQL_ROOT_PASSWORD=change_me_root
MYSQL_DATABASE=comet
MYSQL_USER=comet_app
MYSQL_PASSWORD=change_me_app
APP_ENV=local
EOF

# 3. Secret placeholders (git-ignored by default)
echo "change_me_root" > secrets/db_root_pw.txt
echo "change_me_app"  > secrets/comet_app_pw.txt
chmod 600 secrets/*.txt

# 4. Touch docker files
cat > docker/nginx/default.conf <<'EOF'
# Nginx vhost goes here
EOF

cat > docker/php/Dockerfile <<'EOF'
# PHP-FPM Dockerfile goes here
EOF

# 5. Minimal compose stub
cat > docker-compose.yml <<'EOF'
version: "3.9"
services:
  db:
    image: mysql:8.4
    volumes:
      - db_data:/var/lib/mysql
    secrets: [db_root_pw]
  app:
    build: ./docker/php
    depends_on: [db]
    secrets: [comet_app_pw]
  web:
    image: nginx:1.25-alpine
    depends_on: [app]
secrets:
  db_root_pw:
    file: ./secrets/db_root_pw.txt
  comet_app_pw:
    file: ./secrets/comet_app_pw.txt
volumes:
  db_data:
EOF

echo "✔  COMET skeleton ready.  Next steps:"
echo "   1) cp .env.example .env && edit passwords"
echo "   2) place your PHP source in the repo root"
echo "   3) docker compose up -d   # or podman compose up -d"

