#!/usr/bin/env bash
set -Eeuo pipefail

REPO="${PANEL_REPO:-mahin-wpdev/panel}"
BRANCH="${PANEL_BRANCH:-next-release}"
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"
MODE="${1:-install}"

[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
. /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
  ubuntu:22.04|ubuntu:24.04|debian:12) ;;
  *) echo "Ubuntu 22.04/24.04 or Debian 12 is required" >&2; exit 1 ;;
esac

if [ "$MODE" = "--repair" ]; then
  [ -x "$APP_DIR/installer/repair.sh" ] || { echo "No repairable installation at $APP_DIR" >&2; exit 1; }
  exec env PANEL_DIR="$APP_DIR" PANEL_BRANCH="$BRANCH" bash "$APP_DIR/installer/repair.sh"
elif [ "$MODE" = "--update" ]; then
  [ -x "$APP_DIR/installer/update.sh" ] || { echo "No updateable installation at $APP_DIR" >&2; exit 1; }
  exec env PANEL_DIR="$APP_DIR" PANEL_BRANCH="$BRANCH" bash "$APP_DIR/installer/update.sh"
elif [ "$MODE" != "install" ]; then
  echo "Usage: install.sh [--repair|--update]" >&2
  exit 2
fi

cpu_count="$(nproc 2>/dev/null || echo 1)"
ram_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"
disk_path="$(dirname "$APP_DIR")"
[ -d "$disk_path" ] || disk_path="/"
disk_mb="$(df -Pm "$disk_path" 2>/dev/null | awk 'NR==2 {print $4}' || echo 0)"
[ "$cpu_count" -ge 1 ] || { echo "At least 1 CPU is required" >&2; exit 1; }
[ "${ram_kb:-0}" -ge 900000 ] || { echo "At least 1 GB RAM is required" >&2; exit 1; }
[ "${disk_mb:-0}" -ge 5120 ] || { echo "At least 5 GB free disk space is required" >&2; exit 1; }

if [ -f "$APP_DIR/docker-compose.yml" ] && [ -f "$APP_DIR/.env" ]; then
  echo "Existing installation detected at $APP_DIR." >&2
  echo "Use: sudo bash installer/install.sh --update  or  --repair" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
if [ "${PANEL_SKIP_SYSTEM_SETUP:-0}" != "1" ]; then
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl git openssl docker.io ufw
  timedatectl set-timezone "${TZ:-Asia/Dhaka}" 2>/dev/null || true
  systemctl enable --now docker

  if ! docker compose version >/dev/null 2>&1 && ! command -v docker-compose >/dev/null 2>&1; then
    apt-get install -y -qq docker-compose-v2 2>/dev/null \
      || apt-get install -y -qq docker-compose-plugin 2>/dev/null \
      || apt-get install -y -qq docker-compose
  fi
else
  command -v git >/dev/null && command -v openssl >/dev/null && command -v curl >/dev/null \
    || { echo "git, openssl and curl are required when PANEL_SKIP_SYSTEM_SETUP=1" >&2; exit 1; }
  (docker compose version >/dev/null 2>&1 || command -v docker-compose >/dev/null 2>&1) \
    || { echo "Docker Compose is required when PANEL_SKIP_SYSTEM_SETUP=1" >&2; exit 1; }
fi

if [ "${PANEL_SKIP_NETWORK_CHECK:-0}" != "1" ]; then
  curl -fsSI --max-time 10 https://github.com/ >/dev/null || { echo "Outbound HTTPS/network check failed" >&2; exit 1; }
fi
if command -v ufw >/dev/null 2>&1; then
  ufw allow 80/tcp >/dev/null 2>&1 || true
  ufw allow 443/tcp >/dev/null 2>&1 || true
  ufw allow 1812/udp >/dev/null 2>&1 || true
  ufw allow 1813/udp >/dev/null 2>&1 || true
  ufw allow 3799/udp >/dev/null 2>&1 || true
fi

compose() {
  if docker compose version >/dev/null 2>&1; then docker compose "$@"; else docker-compose "$@"; fi
}

if [ ! -f "$APP_DIR/docker-compose.yml" ]; then
  [ ! -e "$APP_DIR" ] || [ -z "$(find "$APP_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || { echo "$APP_DIR exists and is not an installation" >&2; exit 1; }
  rm -rf "$APP_DIR"
  git clone --depth 1 --branch "$BRANCH" "https://github.com/$REPO.git" "$APP_DIR"
fi
cd "$APP_DIR"
mkdir -p backups
chmod 700 backups

rand() { openssl rand -hex "$1"; }
public_host="${PANEL_HOST:-$(curl -fsS --max-time 8 https://api.ipify.org || hostname -I | awk '{print $1}')}"
site_address="${PANEL_SITE_ADDRESS:-:80}"
admin_user="${PANEL_ADMIN_USER:-admin}"
admin_password="${PANEL_ADMIN_PASSWORD:-$(rand 10)}"

[[ "$admin_user" =~ ^[A-Za-z0-9_.@-]{3,45}$ ]] || { echo "Invalid PANEL_ADMIN_USER" >&2; exit 1; }
[ -n "$public_host" ] || { echo "Unable to determine PANEL_HOST" >&2; exit 1; }
[[ "$public_host" != *$'\n'* && "$site_address" != *$'\n'* ]] || { echo "Host values cannot contain newlines" >&2; exit 1; }

public_origin="http://$public_host"
if [[ "$site_address" != :* ]]; then public_origin="https://$site_address"; fi
admin_hash="$(printf %s "$admin_password" | sha1sum | awk '{print $1}')"

umask 077
cat > .env <<EOF
COMPOSE_PROJECT_NAME=jm-broadband-panel
TZ=${TZ:-Asia/Dhaka}
SITE_ADDRESS=$site_address
PUBLIC_HOST=$public_host
PUBLIC_ORIGIN=$public_origin
COMPANY_NAME=${PANEL_COMPANY_NAME:-JM Broadband}
ADMIN_USERNAME=$admin_user
ADMIN_FULLNAME=${PANEL_ADMIN_NAME:-Administrator}
ADMIN_PASSWORD_HASH=$admin_hash
DB_NAME=jm_panel
DB_USER=jm_panel
DB_PASSWORD=$(rand 24)
DB_ROOT_PASSWORD=$(rand 24)
RADIUS_SHARED_SECRET=$(rand 24)
RADIUS_CLIENT_NETWORK=${RADIUS_CLIENT_NETWORK:-0.0.0.0/0}
WHATSAPP_ADMIN_API_KEY=$(rand 32)
WHATSAPP_API_KEY_PEPPER=$(rand 32)
WHATSAPP_DELAY_MIN_MS=5000
WHATSAPP_DELAY_MAX_MS=9000
WHATSAPP_DAILY_LIMIT=500
CRON_INTERVAL_SECONDS=60
REMINDER_INTERVAL_SECONDS=3600
USAGE_INTERVAL_SECONDS=300
OLT_INTERVAL_SECONDS=300
BACKUP_DAILY_RETENTION=7
BACKUP_WEEKLY_RETENTION=4
BACKUP_MONTHLY_RETENTION=6
BACKUP_INTERVAL_SECONDS=86400
EOF

compose config >/dev/null
compose build --pull
compose up -d

healthy=0
for _ in $(seq 1 60); do
  expected_services="$(compose config --services | wc -l)"
  running_services="$(compose ps --services --status running | wc -l)"
  if [ "$running_services" -eq "$expected_services" ] \
    && curl -fsS --max-time 5 "$public_origin/" >/dev/null 2>&1 \
    && compose exec -T whatsapp node -e "fetch('http://127.0.0.1:3000/healthz').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))" \
    && compose exec -T radius freeradius -XC >/dev/null 2>&1; then
    healthy=1
    break
  fi
  sleep 5
done
if [ "$healthy" -ne 1 ]; then
  compose ps
  compose logs --tail=120 panel database whatsapp radius gateway
  echo "Installation started but the panel did not become healthy" >&2
  exit 1
fi

cat > install-credentials.txt <<EOF
Panel URL: $public_origin
Admin username: $admin_user
Admin password: $admin_password
WhatsApp setup: $public_origin/?_route=whatsapp
EOF
chmod 600 install-credentials.txt
echo "Installation complete."
cat install-credentials.txt
