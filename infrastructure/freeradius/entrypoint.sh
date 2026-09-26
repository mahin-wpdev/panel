#!/usr/bin/env bash
set -euo pipefail
: "${DB_HOST:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}" "${RADIUS_SHARED_SECRET:?}"
envsubst '${DB_HOST} ${DB_NAME} ${DB_USER} ${DB_PASSWORD}' < /etc/freeradius/3.0/mods-available/sql.jm-template > /etc/freeradius/3.0/mods-available/sql
ln -sf ../mods-available/sql /etc/freeradius/3.0/mods-enabled/sql
cat > /etc/freeradius/3.0/clients.conf <<EOF
client mikrotik {
  ipaddr = ${RADIUS_CLIENT_NETWORK:-0.0.0.0/0}
  secret = ${RADIUS_SHARED_SECRET}
  require_message_authenticator = no
  nas_type = mikrotik
}
EOF
until mariadb --protocol=tcp -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -Nse "SELECT 1 FROM radcheck LIMIT 1" >/dev/null 2>&1; do
  echo "Waiting for RADIUS schema in MariaDB..."
  sleep 2
done
freeradius -XC
if [ "${RADIUS_DEBUG:-0}" = "1" ]; then
  exec freeradius -X
fi
exec freeradius -f -l stdout
