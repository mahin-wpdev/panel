#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
PURGE=0
[ "${1:-}" = "--purge-data" ] && PURGE=1
[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
cd "$APP_DIR"
compose() { if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi; }
if [ "$PURGE" -eq 1 ]; then
  compose down -v --remove-orphans
  rm -rf "$APP_DIR"
  echo "Application and Docker volumes removed. External backups outside $APP_DIR are untouched."
else
  if [ -f .env ]; then
    mkdir -p backups && chmod 700 backups
    compose up -d database backup >/dev/null || true
    compose exec -T backup /usr/local/bin/jm-backup once >/dev/null || true
  fi
  compose down --remove-orphans
  echo "Application containers removed/stopped. Data volumes, .env and backups are retained in $APP_DIR."
  echo "Run with --purge-data only when permanent deletion is intended."
fi
