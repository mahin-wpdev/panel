# V-SOL OLT pagination fix (NOT YET DEPLOYED)

The OLT prints `Press any key to continue (Q to quit)` after about 22 ONU rows.
A reader that accepts a partial page can undercount the 26 ONUs already stored in the Panel.
This patch waits for the final `epon#` prompt, sends a space for each page, and
treats incomplete responses as errors instead of deleting or misclassifying ONUs.

## Files and targets

- `OltManager.php` → `/www/wwwroot/27.147.201.165/system/autoload/OltManager.php` (the auto-sync adapter)
- `panel-OltManager.php` → `/www/wwwroot/27.147.201.165/panel/system/autoload/OltManager.php`
- `OltOnuRemoval.php` → `/www/wwwroot/27.147.201.165/panel/system/autoload/OltOnuRemoval.php`
- `apply.sh` → run as root after staging the files in `/home/mahin/olt-pager-stage`.

## Installation on the authorized machine

Copy these four files as a directory into the VM user's
`/home/mahin/olt-pager-stage` using the user's own normal SSH/SFTP access.
Then run from the Ubuntu VM:

```bash
sudo sh /home/mahin/olt-pager-stage/apply.sh
```

The script checks existing source markers and the persistent key, lint-checks
all staged PHP files, makes `/home/mahin/olt-pager-predeploy-20260922.tar.gz`,
and replaces only the three listed PHP files. Abort and inspect if it reports
`ABORT_...`; do not force an overwrite.

## Verification

After the next scheduled minute, check `tbl_olts.last_sync_at`,
`tbl_olts.last_sync_status` and the latest `tbl_olt_sync_logs.onu_found`.
The old 22-count is *not* the expected result for a complete CLI response;
verify the actual full inventory before making any claim of 26.
Do not remove an active ONU or change OLT authentication mode for a test.
Only a deliberately retired, unassigned, offline ONU is a suitable removal
acceptance test; `offline-onu del` exact behavior remains untested end-to-end.

## Rollback

The predeploy tar preserves all three files at original relative paths.
A qualified operator can restore exactly those paths after reconciling any
subsequent live changes. The earlier ONU action backup is separate:
`/home/mahin/onu-actions-predeploy-20260922.tar.gz`.

**This package being committed or pushed to GitHub does not deploy it.**
