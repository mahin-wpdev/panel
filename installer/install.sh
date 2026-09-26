#!/usr/bin/env bash
set -Eeuo pipefail

REPO="${PANEL_REPO:-mahin-wpdev/panel}"
BRANCH="${PANEL_BRANCH:-next-release}"
APP_DIR="${PANEL_DIR:-/opt/jm-panel}"

[ "${EUID}" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
. /etc/os-release
case "${ID:-}" in
  ubuntu|debian) ;;
  *) echo "Ubuntu 22.04/24.04 or Debian 12 is required" >&2; exit 1 ;;
esac

export DEBIAN_FRONTEND=noninteractive
if [ "${PANEL_SKIP_SYSTEM_SETUP:-0}" != "1" ]; then
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl git openssl docker.io
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
WhatsApp dashboard: $public_origin/whatsapp/
EOF
chmod 600 install-credentials.txt
echo "Installation complete."
cat install-credentials.txt
