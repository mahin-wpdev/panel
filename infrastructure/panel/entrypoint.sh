#!/usr/bin/env bash
set -euo pipefail
required=(DB_HOST DB_NAME DB_USER DB_PASSWORD ADMIN_PASSWORD_HASH)
for name in "\${required[@]}"; do
  [ -n "\${!name:-}" ] || { echo "Missing required environment: $name" >&2; exit 1; }
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
if ! "\${MYSQL[@]}" -Nse "SHOW TABLES LIKE 'tbl_users'" | grep -q tbl_users; then
  "\${MYSQL[@]}" < /var/www/html/install/phpnuxbill.sql
  "\${MYSQL[@]}" < /var/www/html/install/radius.sql
  for migration in /var/www/html/autorecharge/migration.sql /var/www/html/system/mobile/push-migration.sql /var/www/html/system/mobile/support-tickets-migration.sql /var/www/html/system/mobile/traffic-peaks-migration.sql; do
    [ ! -f "$migration" ] || "\${MYSQL[@]}" --force < "$migration"
  done
fi
admin_user="\${ADMIN_USERNAME:-admin}"; admin_name="\${ADMIN_FULLNAME:-Administrator}"; company="\${COMPANY_NAME:-JM Broadband}"
"\${MYSQL[@]}" --execute="INSERT INTO tbl_users (id,root,photo,username,fullname,password,phone,email,city,subdistrict,ward,user_type,status,data,creationdate) VALUES (1,0,'/admin.default.png','$admin_user','$admin_name','\${ADMIN_PASSWORD_HASH}','','','','','','SuperAdmin','Active',NULL,NOW()) ON DUPLICATE KEY UPDATE username=VALUES(username),fullname=VALUES(fullname); INSERT INTO tbl_appconfig (setting,value) VALUES ('CompanyName','$company'),('radius_enable','1') ON DUPLICATE KEY UPDATE value=VALUES(value);"
printf '%s' "\${WHATSAPP_API_KEY:-}" > /var/www/html/system/secure/whatsapp-api-key
chmod 600 /var/www/html/system/secure/whatsapp-api-key
chown -R www-data:www-data /var/www/html/system/uploads /var/www/html/system/cache /var/www/html/ui/compiled /var/www/html/system/secure
exec "$@"
