# OLT / ONU production change — 2026-09-22

## Findings
- The live VSOL EPON-1U4P OLT auto-sync is succeeding once per minute under the `www` crontab.
- The separate five-minute root cron was a duplicate and has been removed; backup:
  `/home/mahin/olt-root-crontab-before-dedup-20260922`.
- At the audit, the OLT inventory contained 22 ONU entries while the Panel had 26 historical ONU rows.
  No historical rows were automatically deleted or mass-marked offline.
- The production auto-sync uses the persistent 32-byte key in
  `/www/wwwroot/27.147.201.165/system/secure/olt-encryption.key`.
  Manual Panel operations were still pointing at the obsolete /tmp key and are now
  wired to read the same existing key without generating a replacement.

## Remove from OLT
- Admin / SuperAdmin only; POST and CSRF required, plus the MAC from the displayed row.
- Visible only for an OFFLINE, unassigned ONU. Online, assigned, removed,
  unsupported, missing or ambiguous ONUs fail closed.
- Uses the same lock as automatic sync; re-reads the row after locking.
- Logs into the OLT via the verified V-SOL Telnet adapter; checks that exactly one
  live PON/ONU ID matches the stored MAC and its live state is offline/powerdown.
- Sends only `offline-onu del <numeric ONU ID>` from the matching `olt <PON>`
  context. No `all` or multi-ONU command is used.
- Reads OLT inventory again before recording a successful removal. The Panel
  retains historical rows and changes only the verified entry's status to REMOVED.
  An ONU that auto-registers again will reappear in the regular sync.
- Current OLT auth mode is DISABLED, so removal is not necessarily permanent.
  The UI confirmation explicitly warns that it may register again.
- An unrelated customer-unassign form is handled separately and never sends a
  delete command to the OLT.

## Production verification
- Tested manual OLT credentials via read-only connection: Connected.
- PHP lint passed for OltManager.php, OltOnuRemoval.php, onus.php.
- Synthetic inventory parser test passed; online-ONU fail-closed guard passed.
- No actual ONU deletion was performed in tests.
- On-device authenticated click-through and actual destructive OLT removal
  remain untested until an intentionally retired ONU is selected.

## Rollback
- Production file archive: `/home/mahin/onu-actions-predeploy-20260922.tar.gz`.
- The duplicate-cron backup is separately stored at the path listed above.
- Do not change PPPoE, RADIUS, OLT authentication mode or reboot the OLT
  to test this feature.
