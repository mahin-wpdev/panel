#!/usr/bin/env bash
set -euo pipefail
: "\${DB_HOST:?}" "\${DB_NAME:?}" "\${DB_USER:?}" "\${DB_PASSWORD:?}" "\${RADIUS_SHARED_SECRET:?}"
envsubst < /etc/freeradius/3.0/mods-available/sql.jm-template > /etc/freeradius/3.0/mods-available/sql
ln -sf ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
cat > /etc/freeradius/3.0/clients.conf <<EOF
client mikrotik {
  ipaddr = \${RADIUS_CLIENT_NETWORK:-0.0.0.0/0}
  secret = \${RADIUS_SHARED_SECRET}
  require_message_authenticator = no
  nas_type = mikrotik
}
EOF
exec freeradius -f
