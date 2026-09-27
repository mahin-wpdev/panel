#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
interval="${CRON_INTERVAL_SECONDS:-60}"
reminder_every="${REMINDER_INTERVAL_SECONDS:-3600}"
usage_every="${USAGE_INTERVAL_SECONDS:-300}"
olt_every="${OLT_INTERVAL_SECONDS:-300}"
last_reminder=0
last_usage=0
last_olt=0

run_php() {
  local script="$1" dir name
  [ -f "$script" ] || {
    echo "Cron task missing: $script" >&2
    return 1
  }
  dir="$(dirname "$script")"
  name="$(basename "$script")"
  (
    cd "$dir"
    php "$name"
  )
}

while true; do
  now="$(date +%s)"
  if run_php system/cron.php; then
    date -Is > /tmp/jm-cron-heartbeat
  else
    echo "Critical cron task failed: system/cron.php" >&2
    rm -f /tmp/jm-cron-heartbeat
  fi
  if [ "$((now-last_reminder))" -ge "$reminder_every" ]; then
    run_php system/cron_reminder.php || echo "Cron task failed: system/cron_reminder.php" >&2
    last_reminder="$now"
  fi
  if [ "$((now-last_usage))" -ge "$usage_every" ]; then
    run_php system/cron_isp_usage.php || echo "Cron task failed: system/cron_isp_usage.php" >&2
    last_usage="$now"
  fi
  if [ "$((now-last_olt))" -ge "$olt_every" ]; then
    run_php system/olt_sync_cron.php || echo "Cron task failed: system/olt_sync_cron.php" >&2
    last_olt="$now"
  fi
  sleep "$interval"
done
