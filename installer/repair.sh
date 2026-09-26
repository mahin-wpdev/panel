#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
BRANCH="${PANEL_BRANCH:-next-release}"
[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
cd "$APP_DIR"
[ -f .env ] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }
compose() { if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi; }
echo "Creating repair safety backup..."
mkdir -p backups && chmod 700 backups
compose up -d database backup >/dev/null
compose exec -T backup /usr/local/bin/jm-backup once >/dev/null
git fetch --depth 1 origin "$BRANCH"
git reset --hard FETCH_HEAD
compose config >/dev/null
compose build --pull
compose up -d
for _ in $(seq 1 60); do
  expected="$(compose config --services | wc -l)"
  running="$(compose ps --services --status running | wc -l)"
  if [ "$running" -eq "$expected" ] && compose exec -T panel curl -fsS http://127.0.0.1/ >/dev/null 2>&1 && compose exec -T whatsapp node -e "fetch('http://127.0.0.1:3000/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" && compose exec -T radius freeradius -XC >/dev/null 2>&1; then
    echo "Repair completed successfully."
    exit 0
  fi
  sleep 5
done
compose ps --all
compose logs --tail=200
echo "Repair did not reach a healthy state." >&2
exit 1
