#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
BRANCH="${PANEL_BRANCH:-next-release}"
REPO="${PANEL_REPO:-mahin-wpdev/panel}"

[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
cd "$APP_DIR"
[ -f .env ] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }

compose() {
  if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi
}

root_password="$(sed -n 's/^DB_ROOT_PASSWORD=//p' .env)"
[ -n "$root_password" ] || { echo "DB_ROOT_PASSWORD is missing" >&2; exit 1; }
backup="backup-before-update-$(date +%Y%m%d-%H%M%S).sql.gz"
compose exec -T database mariadb-dump -uroot -p"$root_password" --all-databases | gzip > "$backup"
[ -s "$backup" ] || { echo "Database backup failed" >&2; exit 1; }

if [ ! -d .git ]; then
  git init
  git remote add origin "https://github.com/$REPO.git"
fi
git fetch --depth 1 origin "$BRANCH"
git reset --hard FETCH_HEAD
compose config >/dev/null
compose build --pull
compose up -d
compose ps
echo "Updated successfully. Backup: $APP_DIR/$backup"
