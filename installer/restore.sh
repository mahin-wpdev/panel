#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
ARCHIVE="${1:-}"
[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
[ -n "$ARCHIVE" ] || { echo "Usage: sudo bash installer/restore.sh BACKUP_FILE" >&2; exit 2; }
cd "$APP_DIR"
[ -f .env ] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }
compose() { if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi; }
case "$ARCHIVE" in
  /*) ;;
  *) ARCHIVE="$APP_DIR/$ARCHIVE" ;;
esac
[ -f "$ARCHIVE" ] || { echo "Backup not found: $ARCHIVE" >&2; exit 1; }
case "$ARCHIVE" in "$APP_DIR/backups/"*) ;; *) echo "Backup must be inside $APP_DIR/backups" >&2; exit 1 ;; esac
rel="/backups/${ARCHIVE#"$APP_DIR/backups/"}"
echo "Creating pre-restore safety backup..."
compose up -d database backup >/dev/null
compose exec -T backup /usr/local/bin/jm-backup once >/dev/null
compose stop gateway radius whatsapp panel cron backup >/dev/null 2>&1 || true
compose up -d database >/dev/null
compose run --rm --no-deps backup restore "$rel"
if [ -s .env.restored ]; then
  cp -a .env ".env.before-restore-$(date +%Y%m%d-%H%M%S)"
  mv .env.restored .env
  chmod 600 .env
fi
compose config >/dev/null
compose build
compose up -d
for _ in $(seq 1 60); do
  expected="$(compose config --services | wc -l)"
  running="$(compose ps --services --status running | wc -l)"
  if [ "$running" -eq "$expected" ] && compose exec -T panel curl -fsS http://127.0.0.1/ >/dev/null 2>&1 && compose exec -T radius freeradius -XC >/dev/null 2>&1; then
    echo "Restore completed successfully: $ARCHIVE"
    exit 0
  fi
  sleep 5
done
compose ps --all
compose logs --tail=200
echo "Restore data completed but services are not healthy." >&2
exit 1
