#!/usr/bin/env bash
set -Eeuo pipefail
REPO="\${PANEL_REPO:-mahin-wpdev/panel}"; BRANCH="\${PANEL_BRANCH:-next-release}"; APP_DIR="\${PANEL_DIR:-/opt/jm-panel}"
[ "\${EUID}" -eq 0 ] || { echo "Run as root"; exit 1; }
. /etc/os-release
case "\${ID:-}" in ubuntu|debian) ;; *) echo "Ubuntu 22.04/24.04 or Debian 12 is required"; exit 1;; esac
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl git openssl docker.io
systemctl enable --now docker
docker compose version >/dev/null 2>&1 || apt-get install -y -qq docker-compose-v2 2>/dev/null || apt-get install -y -qq docker-compose-plugin
if [ ! -f "$APP_DIR/docker-compose.yml" ]; then
  mkdir -p "$APP_DIR"
  curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" | tar xz --strip-components=1 -C "$APP_DIR"
fi
cd "$APP_DIR"
rand() { openssl rand -hex "$1"; }
public_host="\${PANEL_HOST:-$(curl -fsS --max-time 8 https://api.ipify.org || hostname -I | awk '{print $1}')}"
site_address="\${PANEL_SITE_ADDRESS:-:80}"; public_origin="http://$public_host"
[[ "$site_address" == :* ]] || public_origin="https://$site_address"
admin_user="\${PANEL_ADMIN_USER:-admin}"; admin_password="\${PANEL_ADMIN_PASSWORD:-$(rand 10)}"
admin_hash="$(printf %s "$admin_password" | sha1sum | awk '{print $1}')"
umask 077
cat > .env <<EOF
COMPOSE_PROJECT_NAME=jm-broadband-panel
TZ=\${TZ:-Asia/Dhaka}
SITE_ADDRESS=$site_address
PUBLIC_HOST=$public_host
PUBLIC_ORIGIN=$public_origin
COMPANY_NAME=\${PANEL_COMPANY_NAME:-JM Broadband}
ADMIN_USERNAME=$admin_user
ADMIN_FULLNAME=\${PANEL_ADMIN_NAME:-Administrator}
ADMIN_PASSWORD_HASH=$admin_hash
DB_NAME=jm_panel
DB_USER=jm_panel
DB_PASSWORD=$(rand 24)
DB_ROOT_PASSWORD=$(rand 24)
RADIUS_SHARED_SECRET=$(rand 24)
RADIUS_CLIENT_NETWORK=\${RADIUS_CLIENT_NETWORK:-0.0.0.0/0}
WHATSAPP_ADMIN_API_KEY=$(rand 32)
WHATSAPP_API_KEY_PEPPER=$(rand 32)
WHATSAPP_DELAY_MIN_MS=5000
WHATSAPP_DELAY_MAX_MS=9000
WHATSAPP_DAILY_LIMIT=500
EOF
docker compose build --pull
docker compose up -d
cat > install-credentials.txt <<EOF
Panel URL: $public_origin
Admin username: $admin_user
Admin password: $admin_password
WhatsApp dashboard: $public_origin/whatsapp/
EOF
chmod 600 install-credentials.txt
echo "Installation complete."; cat install-credentials.txt
