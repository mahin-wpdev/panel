#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="\${PANEL_DIR:-/opt/jm-panel}"; BRANCH="\${PANEL_BRANCH:-next-release}"
[ "\${EUID}" -eq 0 ] || { echo "Run as root"; exit 1; }
cd "$APP_DIR"
root_password="$(sed -n 's/^DB_ROOT_PASSWORD=//p' .env)"
docker compose exec -T database mariadb-dump -uroot -p"$root_password" --all-databases | gzip > "backup-before-update-$(date +%Y%m%d-%H%M%S).sql.gz"
git fetch origin "$BRANCH"; git checkout "$BRANCH"; git pull --ff-only origin "$BRANCH"
docker compose build --pull
docker compose up -d
docker compose ps
