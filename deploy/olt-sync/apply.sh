#!/bin/sh
set -eu
ROOT=/www/wwwroot/27.147.201.165
STAGE=$(CDPATH= cd "$(dirname "$0")" && pwd)
BACKUP=/home/mahin/olt-pager-predeploy-20260922.tar.gz
PHP=/www/server/php/83/bin/php
OUTER="$ROOT/system/autoload/OltManager.php"
PANEL="$ROOT/panel/system/autoload/OltManager.php"
REMOVE="$ROOT/panel/system/autoload/OltOnuRemoval.php"
KEY="$ROOT/system/secure/olt-encryption.key"
[ ! -e "$BACKUP" ] || { echo ABORT_BACKUP_ALREADY_EXISTS; exit 2; }
[ "$(stat -c %s "$KEY")" -eq 32 ] || { echo ABORT_KEY_UNAVAILABLE; exit 3; }
for n in OltManager.php panel-OltManager.php OltOnuRemoval.php; do
 [ -s "$STAGE/$n" ] || { echo "ABORT_MISSING_STAGE_$n"; exit 4; }
 "$PHP" -n -l "$STAGE/$n" >/dev/null || exit 5
done
# Verify live files still have the exact old single-page reader.
grep -Fq "if(stripos(\$out,\$until)!==false)break" "$OUTER" || { echo ABORT_OUTER_CHANGED; exit 6; }
grep -Fq "if(stripos(\$out,\$until)!==false)break" "$PANEL" || { echo ABORT_PANEL_CHANGED; exit 7; }
grep -Fq "if (strpos(\$output, \$prompt) !== false) return \$output" "$REMOVE" || { echo ABORT_REMOVAL_CHANGED; exit 8; }
umask 077
tar -czf "$BACKUP" -C "$ROOT" \
 system/autoload/OltManager.php \
 panel/system/autoload/OltManager.php \
 panel/system/autoload/OltOnuRemoval.php
chmod 600 "$BACKUP"
DONE=0
rollback() {
 if [ "$DONE" -ne 1 ]; then
  tar -xzf "$BACKUP" -C "$ROOT" || true
  echo ROLLBACK_ATTEMPTED
 fi
}
trap rollback EXIT
install -m 0644 -o www -g www "$STAGE/OltManager.php" "$OUTER.pager-new"
install -m 0644 -o www -g www "$STAGE/panel-OltManager.php" "$PANEL.pager-new"
install -m 0644 -o www -g www "$STAGE/OltOnuRemoval.php" "$REMOVE.pager-new"
mv -f "$OUTER.pager-new" "$OUTER"
mv -f "$PANEL.pager-new" "$PANEL"
mv -f "$REMOVE.pager-new" "$REMOVE"
"$PHP" -n -l "$OUTER" >/dev/null
"$PHP" -n -l "$PANEL" >/dev/null
"$PHP" -n -l "$REMOVE" >/dev/null
DONE=1
trap - EXIT
echo PAGER_CODE_INSTALLED_LIVE_SYNC_UNVERIFIED
echo BACKUP_CREATED
