#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${DB_HOST:?}" "${DB_ROOT_PASSWORD:?}"
DB_NAME="${DB_NAME:-jm_panel}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
DAILY_RETENTION="${BACKUP_DAILY_RETENTION:-7}"
WEEKLY_RETENTION="${BACKUP_WEEKLY_RETENTION:-4}"
MONTHLY_RETENTION="${BACKUP_MONTHLY_RETENTION:-6}"
INTERVAL="${BACKUP_INTERVAL_SECONDS:-86400}"
mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly" "$BACKUP_DIR/monthly"

db=(mariadb --protocol=tcp -h"$DB_HOST" -uroot -p"$DB_ROOT_PASSWORD")
dump=(mariadb-dump --protocol=tcp -h"$DB_HOST" -uroot -p"$DB_ROOT_PASSWORD" --single-transaction --routines --events --triggers --all-databases)

wait_db() {
  until "${db[@]}" -Nse "SELECT 1" >/dev/null 2>&1; do sleep 2; done
}

prune() {
  local dir="$1" keep="$2"
  [ "$keep" -ge 1 ] || return 0
  mapfile -t files < <(find "$dir" -maxdepth 1 -type f -name 'jm-panel-*.tar.gz' -printf '%T@ %p\n' | sort -nr | awk '{print $2}')
  if [ "${#files[@]}" -gt "$keep" ]; then
    printf '%s\n' "${files[@]:$keep}" | xargs -r rm -f --
  fi
}

backup_once() {
  wait_db
  exec 9>"$BACKUP_DIR/.backup.lock"
  flock -w 120 9 || { echo "Timed out waiting for backup lock" >&2; exec 9>&-; return 1; }
  local ts tmp archive day dow
  ts="$(date +%Y%m%d-%H%M%S)"
  day="$(date +%d)"
  dow="$(date +%u)"
  tmp="$(mktemp -d)"
  mkdir -p "$tmp/runtime" "$tmp/panel" "$tmp/whatsapp" "$tmp/caddy"
  "${dump[@]}" | gzip -c > "$tmp/database.sql.gz"
  [ ! -f /runtime/.env ] || cp -a /runtime/.env "$tmp/runtime/.env"
  [ ! -d /source/uploads ] || cp -a /source/uploads "$tmp/panel/uploads"
  [ ! -d /source/secure ] || cp -a /source/secure "$tmp/panel/secure"
  if [ -d /source/whatsapp-data ]; then
    cp -a /source/whatsapp-data "$tmp/whatsapp/data"
    if [ -f /source/whatsapp-data/baileys.sqlite ]; then
      sqlite3 /source/whatsapp-data/baileys.sqlite ".timeout 5000" ".backup '$tmp/whatsapp/data/baileys.sqlite'"
      rm -f "$tmp/whatsapp/data/baileys.sqlite-wal" "$tmp/whatsapp/data/baileys.sqlite-shm"
    fi
  fi
  [ ! -d /source/whatsapp-auth ] || cp -a /source/whatsapp-auth "$tmp/whatsapp/auth"
  [ ! -d /source/caddy-data ] || cp -a /source/caddy-data "$tmp/caddy/data"
  [ ! -d /source/caddy-config ] || cp -a /source/caddy-config "$tmp/caddy/config"
  printf '%s\n' "$ts" > "$tmp/BACKUP_TIMESTAMP"
  archive="$BACKUP_DIR/daily/jm-panel-$ts.tar.gz"
  tar -C "$tmp" -czf "$archive" .
  gzip -t "$tmp/database.sql.gz"
  tar -tzf "$archive" >/dev/null
  if [ "$dow" = "7" ]; then cp -a "$archive" "$BACKUP_DIR/weekly/"; fi
  if [ "$day" = "01" ]; then cp -a "$archive" "$BACKUP_DIR/monthly/"; fi
  prune "$BACKUP_DIR/daily" "$DAILY_RETENTION"
  prune "$BACKUP_DIR/weekly" "$WEEKLY_RETENTION"
  prune "$BACKUP_DIR/monthly" "$MONTHLY_RETENTION"
  rm -rf "$tmp"
  flock -u 9
  exec 9>&-
  echo "$archive"
}

restore_archive() {
  local archive="$1" tmp
  [ -f "$archive" ] || { echo "Backup not found: $archive" >&2; exit 1; }
  tar -tzf "$archive" >/dev/null
  tmp="$(mktemp -d)"
  tar -xzf "$archive" -C "$tmp"
  [ -s "$tmp/database.sql.gz" ] || { echo "database.sql.gz missing from backup" >&2; exit 1; }
  wait_db
  gunzip -c "$tmp/database.sql.gz" | "${db[@]}"
  for pair in "panel/uploads:/restore/uploads" "panel/secure:/restore/secure" "whatsapp/data:/restore/whatsapp-data" "whatsapp/auth:/restore/whatsapp-auth" "caddy/data:/restore/caddy-data" "caddy/config:/restore/caddy-config"; do
    src="${pair%%:*}"; dst="${pair#*:}"
    if [ -d "$tmp/$src" ]; then rm -rf "$dst"/* "$dst"/.[!.]* "$dst"/..?* 2>/dev/null || true; cp -a "$tmp/$src"/. "$dst"/; fi
  done
  [ ! -f "$tmp/runtime/.env" ] || cp -a "$tmp/runtime/.env" /restore-runtime/.env.restored
  rm -rf "$tmp"
  echo "Restore completed from $archive"
}

case "${1:-daemon}" in
  once) backup_once ;;
  restore) [ -n "${2:-}" ] || { echo "Usage: jm-backup restore /backups/...tar.gz" >&2; exit 2; }; restore_archive "$2" ;;
  daemon) while true; do backup_once || true; sleep "$INTERVAL"; done ;;
  *) echo "Usage: jm-backup [daemon|once|restore ARCHIVE]" >&2; exit 2 ;;
esac
