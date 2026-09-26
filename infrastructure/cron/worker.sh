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
  local script="$1"
  if [ -f "$script" ]; then
    php "$script" || echo "Cron task failed: $script" >&2
  fi
}

while true; do
  now="$(date +%s)"
  run_php system/cron.php
  if [ "$((now-last_reminder))" -ge "$reminder_every" ]; then run_php system/cron_reminder.php; last_reminder="$now"; fi
  if [ "$((now-last_usage))" -ge "$usage_every" ]; then run_php system/cron_isp_usage.php; last_usage="$now"; fi
  if [ "$((now-last_olt))" -ge "$olt_every" ]; then run_php system/olt_sync_cron.php; last_olt="$now"; fi
  date -Is > /tmp/jm-cron-heartbeat
  sleep "$interval"
done
