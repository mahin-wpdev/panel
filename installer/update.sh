#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
BRANCH="${PANEL_BRANCH:-next-release}"
REPO="${PANEL_REPO:-mahin-wpdev/panel}"
UPDATE_HEALTH_ATTEMPTS="${PANEL_UPDATE_HEALTH_ATTEMPTS:-60}"
ROLLBACK_HEALTH_ATTEMPTS="${PANEL_ROLLBACK_HEALTH_ATTEMPTS:-60}"

if [ "${EUID}" -ne 0 ] && [ "${_PANEL_TEST_ALLOW_NON_ROOT:-0}" != "1" ]; then
  echo "Run as root" >&2
  exit 1
fi
cd "$APP_DIR"
[ -f .env ] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }

compose() {
  if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi
}

wait_healthy() {
  local attempts="${1:-60}" expected running
  for _ in $(seq 1 "$attempts"); do
    expected="$(compose config --services | wc -l)"
    running="$(compose ps --services --status running | wc -l)"
    if [ "$running" -eq "$expected" ] \
      && compose exec -T panel curl -fsS --max-time 5 http://127.0.0.1/ >/dev/null 2>&1 \
      && compose exec -T whatsapp node -e "fetch('http://127.0.0.1:3000/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" \
      && compose exec -T radius freeradius -XC >/dev/null 2>&1; then
      return 0
    fi
    sleep 5
  done
  return 1
}
root_password="$(sed -n 's/^DB_ROOT_PASSWORD=//p' .env)"
[ -n "$root_password" ] || { echo "DB_ROOT_PASSWORD is missing" >&2; exit 1; }

previous_commit="$(git rev-parse --verify HEAD 2>/dev/null || true)"
if [ -z "$previous_commit" ]; then
  echo "Safe update requires an existing Git checkout with a valid HEAD." >&2
  exit 1
fi
if ! git remote get-url origin >/dev/null 2>&1; then
  git remote add origin "https://github.com/$REPO.git"
fi

timestamp="$(date +%Y%m%d-%H%M%S)"
backup="backup-before-update-${timestamp}.sql.gz"
echo "Creating database backup: $APP_DIR/$backup"
compose exec -T database mariadb-dump -uroot -p"$root_password" --all-databases | gzip > "$backup"
[ -s "$backup" ] || { echo "Database backup failed" >&2; exit 1; }
gzip -t "$backup"
mkdir -p backups
chmod 700 backups
compose up -d backup >/dev/null
full_backup="$(compose exec -T backup /usr/local/bin/jm-backup once | tail -n 1 | tr -d '\r')"
[ -n "$full_backup" ] || { echo "Full-state backup failed" >&2; exit 1; }
echo "Full-state backup: $full_backup"

rollback() {
  local reason="${1:-update failure}" restore_status=0
  trap - ERR
  set +e
  echo "Update failed ($reason). Rolling back to $previous_commit..." >&2
  compose stop gateway radius whatsapp panel cron backup >/dev/null 2>&1
  git reset --hard "$previous_commit"
  compose up -d database
  gunzip -c "$backup" | compose exec -T database mariadb -uroot -p"$root_password"
  restore_status=$?
  if [ "$restore_status" -ne 0 ]; then
    echo "Database restore failed. Backup retained at $APP_DIR/$backup" >&2
    compose ps --all
    exit 1
  fi
  compose config >/dev/null
  compose build
  compose up -d
  if wait_healthy "$ROLLBACK_HEALTH_ATTEMPTS"; then
    echo "Rollback completed successfully. Backup retained at $APP_DIR/$backup" >&2
  else
    echo "Rollback source/database restored, but services are not healthy." >&2
    compose ps --all
    compose logs --tail=200 panel database whatsapp radius gateway
  fi
  exit 1
}

trap 'rollback "command failed at line $LINENO"' ERR

echo "Fetching $BRANCH..."
git fetch --depth 1 origin "$BRANCH"
target_commit="$(git rev-parse FETCH_HEAD)"
git reset --hard FETCH_HEAD

compose config >/dev/null
compose build --pull
compose up -d

# CI-only fault injection used to prove the rollback path. Never set in production.
if [ "${_PANEL_TEST_FORCE_UPDATE_FAILURE:-0}" = "1" ]; then
  false
fi

if ! wait_healthy "$UPDATE_HEALTH_ATTEMPTS"; then
  compose ps --all
  compose logs --tail=200 panel database whatsapp radius gateway
  false
fi

trap - ERR
compose ps
echo "Updated successfully: $previous_commit -> $target_commit"
echo "Backup: $APP_DIR/$backup"
