#!/usr/bin/env bash
set -euo pipefail
required=(DB_HOST DB_NAME DB_USER DB_PASSWORD ADMIN_PASSWORD_HASH)
for name in "${required[@]}"; do
  [ -n "${!name:-}" ] || { echo "Missing required environment: $name" >&2; exit 1; }
done
cat > /var/www/html/config.php <<PHP
<?php
\$protocol = (!empty(\$_SERVER["HTTP_X_FORWARDED_PROTO"])) ? \$_SERVER["HTTP_X_FORWARDED_PROTO"] . "://" : ((!empty(\$_SERVER["HTTPS"]) && \$_SERVER["HTTPS"] !== "off") ? "https://" : "http://");
\$host = \$_SERVER["HTTP_HOST"] ?? "localhost";
\$baseDir = rtrim(dirname(\$_SERVER["SCRIPT_NAME"] ?? "/"), "/\\\\");
define("APP_URL", \$protocol . \$host . \$baseDir);
\$_app_stage = "Live";
\$db_host = getenv("DB_HOST"); \$db_user = getenv("DB_USER"); \$db_pass = getenv("DB_PASSWORD"); \$db_name = getenv("DB_NAME");
\$radius_host = getenv("RADIUS_DB_HOST") ?: \$db_host; \$radius_user = getenv("RADIUS_DB_USER") ?: \$db_user;
\$radius_pass = getenv("RADIUS_DB_PASSWORD") ?: \$db_pass; \$radius_name = getenv("RADIUS_DB_NAME") ?: \$db_name;
error_reporting(E_ERROR); ini_set("display_errors", "0");
PHP
chmod 640 /var/www/html/config.php
chown www-data:www-data /var/www/html/config.php
until mysqladmin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --silent; do echo "Waiting for MariaDB..."; sleep 2; done
MYSQL=(mysql --protocol=tcp -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME")
fresh_install=0
if ! "${MYSQL[@]}" -Nse "SHOW TABLES LIKE 'tbl_users'" | grep -q tbl_users; then
  fresh_install=1
  "${MYSQL[@]}" < /var/www/html/install/phpnuxbill.sql
fi
if ! "${MYSQL[@]}" -Nse "SHOW TABLES LIKE 'radcheck'" | grep -q radcheck; then
  "${MYSQL[@]}" < /var/www/html/install/radius.sql
fi
/usr/local/bin/jm-panel-migrate
admin_user="${ADMIN_USERNAME:-admin}"; admin_name="${ADMIN_FULLNAME:-Administrator}"; company="${COMPANY_NAME:-JM Broadband}"
sql_escape() { printf %s "$1" | sed "s/'/''/g"; }
admin_user_sql="$(sql_escape "$admin_user")"; admin_name_sql="$(sql_escape "$admin_name")"; company_sql="$(sql_escape "$company")"
if [ "$fresh_install" -eq 1 ]; then
  "${MYSQL[@]}" --execute="UPDATE tbl_users SET username='$admin_user_sql',fullname='$admin_name_sql',password='${ADMIN_PASSWORD_HASH}',status='Active',user_type='SuperAdmin' WHERE id=1; UPDATE tbl_appconfig SET value='$company_sql' WHERE setting='CompanyName'; UPDATE tbl_appconfig SET value='Asia/Dhaka' WHERE setting='timezone'; UPDATE tbl_appconfig SET value='৳' WHERE setting='currency_code'; INSERT INTO tbl_appconfig(setting,value) SELECT 'setup_complete','0' WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting='setup_complete');"
else
  "${MYSQL[@]}" --execute="INSERT INTO tbl_appconfig(setting,value) SELECT 'setup_complete','1' WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting='setup_complete');"
fi
"${MYSQL[@]}" --execute="INSERT INTO tbl_appconfig(setting,value) SELECT 'radius_enable','1' WHERE NOT EXISTS (SELECT 1 FROM tbl_appconfig WHERE setting='radius_enable');"
printf '%s' "${WHATSAPP_API_KEY:-}" > /var/www/html/system/secure/whatsapp-api-key
chmod 600 /var/www/html/system/secure/whatsapp-api-key
chown -R www-data:www-data /var/www/html/system/uploads /var/www/html/system/cache /var/www/html/ui/compiled /var/www/html/system/secure
exec "$@"
