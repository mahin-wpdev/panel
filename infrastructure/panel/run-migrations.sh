#!/usr/bin/env bash
set -euo pipefail
: "${DB_HOST:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"
ROOT="${PANEL_ROOT:-/var/www/html}"
MANIFEST="${MIGRATION_MANIFEST:-$ROOT/database/migrations/manifest.txt}"
MYSQL=(mysql --protocol=tcp -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME")

"${MYSQL[@]}" <<'SQL'
CREATE TABLE IF NOT EXISTS tbl_schema_migrations (
  version VARCHAR(32) NOT NULL,
  path VARCHAR(255) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version),
  UNIQUE KEY uq_schema_migration_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

[ -f "$MANIFEST" ] || { echo "Migration manifest missing: $MANIFEST" >&2; exit 1; }

while IFS='|' read -r version relpath; do
  [ -n "${version// }" ] || continue
  case "$version" in \#*) continue ;; esac
  file="$ROOT/$relpath"
  [ -f "$file" ] || { echo "Migration file missing: $relpath" >&2; exit 1; }
  checksum="$(sha256sum "$file" | awk '{print $1}')"
  existing="$("${MYSQL[@]}" -Nse "SELECT checksum FROM tbl_schema_migrations WHERE version='${version//\'/\'\'}' LIMIT 1")"
  if [ -n "$existing" ]; then
    [ "$existing" = "$checksum" ] || { echo "Migration checksum mismatch: $version $relpath" >&2; exit 1; }
    continue
  fi
  echo "Applying migration $version: $relpath"
  "${MYSQL[@]}" < "$file"
  path_sql="$(printf %s "$relpath" | sed "s/'/''/g")"
  "${MYSQL[@]}" -e "INSERT INTO tbl_schema_migrations(version,path,checksum) VALUES ('$version','$path_sql','$checksum')"
done < "$MANIFEST"
