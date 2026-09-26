#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
cd "$APP_DIR"
[ -f .env ] || { echo "Missing $APP_DIR/.env" >&2; exit 1; }
compose() { if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi; }
mkdir -p backups && chmod 700 backups
compose up -d database backup >/dev/null
archive="$(compose exec -T backup /usr/local/bin/jm-backup once | tail -n 1 | tr -d '\r')"
[ -n "$archive" ] || { echo "Backup service did not return an archive path" >&2; exit 1; }
host_archive="$APP_DIR/backups${archive#/backups}"
[ -s "$host_archive" ] || { echo "Backup archive missing on host: $host_archive" >&2; exit 1; }
echo "Backup complete: $host_archive"
