# Next-release readiness — Panel + Arivo (2026-09-27)

## Scope / isolation
This branch is a **release candidate**, not an authorization to alter the running
server. The Flutter companion lives in a separate repository, also on branch
`next-release`. This Git branch is not a complete image of the running production tree.
Production rollout and rollback must use the site-specific audit notes below;
never run the installer SQL against an existing server.

## Automated release acceptance — 2026-09-27
- `Next release checks` passed on the current `next-release` head after PHP/session and deployment-surface hardening.
- Clean Docker stack build/start and all service health checks passed.
- Public-surface regression verifies config, environment, installer, database, test, backup and secure paths return 404 through the bundled gateway; security headers are asserted.
- FreeRADIUS `Access-Accept` and accounting-start persistence passed against the CI MariaDB schema.
- Schema migrations are repeatable and mobile/reseller/session triggers remain present after restart.
- Full-state backup was created, DB/uploads/WhatsApp state was deliberately mutated, restored, and verified back to the pre-mutation state.
- Forced update failure rolled source/database/services back to the prior healthy state.
- Fresh one-command installer smoke passed on a clean path, including generated credential permissions and expected running-service count.
- Live read-only smoke verified the public panel, mobile server-info, unauthenticated 401 behavior, mobile release manifest, 443/8443 API reachability and APK redirect.
- Arivo ISP Billing `1.1.1+22` download size, SHA-256 digest, package metadata and APK signature matched the published GitHub release.
- No production customer, payment, router or OLT state was modified during this acceptance pass.

## Verified in the audit
- OLT pager fix was confirmed on the running server by two new `synced 26`
  entries after installation; the older 22 was only the first CLI page.
- Flutter tests: 12 passed (including Home server-peak widget); `flutter analyze --no-pub`: no issues.
- Current public Arivo ISP Billing `1.1.1+22` APK was downloaded through the
  live panel redirect; package/version metadata, published size, SHA-256 digest
  and APK signature were independently verified against the GitHub release.
- Local PHP 8.2 syntax checks accepted the touched first-party PHP, payment,
  mobile and installer files. CI separately validates the release set on PHP 8.3.
- Added automatic release safety checks and branch-targeted CI definitions.

## Branch fixes (production deployment status varies)
- Do not track runtime payment and SMS logs. Existing local log files remain
  untracked and are ignored. Existing public *Git history* still contains
  earlier revisions and must be addressed separately.
- Remove the unreferenced legacy `radius_backup.php` endpoint.
- All six installer PHP entry points now refuse execution when a `config.php`
  exists at the Panel root or its immediate parent, preventing destructive
  `install/step4.php` reinstallation on a configured instance.
- Include a sample Nginx deny configuration for `/panel/install/`, sensitive
  file extensions and the legacy endpoint. Matching restrictions were separately
  installed and validated on production on 2026-09-22.
- Keep read-only ONU paging checks, separate OLT/Panel adapters, and
  single-offline-ONU removal protections.
- bKash Personal, bKash Merchant and Nagad SMS webhooks now fail closed unless
  `auto_payment_sms_secret` matches `X-Webhook-Secret`, `X-API-Key`, JSON
  `secret`, or the legacy query-string `secret`. The configured secret is
  never written to webhook logs.
- `autorecharge/migration.sql` now creates the receipt ledger when missing and
  enforces unique `(gateway,trxid)` replay protection before widening legacy
  `transaction_id` values to `VARCHAR(100)`.
- Tracked source backup files were removed from the release branch; runtime
  logs, backup artifacts and signing material remain excluded from release source.

## Blockers BEFORE public rollout / production acceptance
1. **Web-server restriction completed on 2026-09-22.** Installer, SQL,
   webhook logs, and public ZIP archives now return 404; the admin route
   remains 200 and unauthenticated mobile API 401. Retest on future vhost edits.
2. **Historic public payment/SMS log exposure.** Removing files on a new
   branch does not erase existing public commits or existing live log contents.
   Perform authorized incident review, redaction/history strategy and rotate
   any exposed secrets. Do not force-push old branches without coordinating
   with collaborators.
3. **Environment-specific acceptance remains for external/destructive workflows.**
   CI now covers FreeRADIUS Access-Accept, accounting persistence, migration
   repeatability, backup/restore and failed-update rollback. Still test personal
   bKash, merchant bKash and Nagad success/failure/duplicate/incorrect-ref handling;
   real recharge-to-PPPoE enable/expiry; reseller price/profit snapshots and tenant
   isolation; and RouterOS reconnection/month-boundary accounting against an isolated
   CHR or disposable router before production rollout.
4. **OLT removal remains destructive and not tested end-to-end.** Test only a
   deliberately retired offline unassigned ONU with an independent recovery
   plan. OLT authentication mode DISABLED allows possible re-registration.
5. **GitHub CI status and production-version parity** must be checked after
   pushing both next-release branches. An APK signature or green unit test
   does not establish payment or ONU acceptance.

## Safe rollout checklist (later, with operator authorization)
- Take recoverable database and panel backups; rehearse rollback on staging.
- Configure and `nginx -t` the server-side rules from
  `deploy/security/nginx-panel-deny.conf`; confirm installer, SQL and logs
  return 403/404 and normal payment PHP webhook continues working.
- Run branch CI and independent account-level test cases.
- Validate live deployment SHA matches the selected release commit before
  announcing the release. Do not use `install/radius.sql` on production:
  that script drops live RADIUS/accounting tables.

## Server-owned traffic peak correction (deployed to production 2026-09-22)
- Production site-specific deployment: `/www/wwwroot/27.147.201.165/panel/`.
  Two additive `tbl_mobile_radius_speed_*` tables, CLI collector, peak reader,
  authenticated API/dashboard and `mobile-data?section=home` mapping are live.
  The live API retains production's `jm_live_snapshot_cached` optimization.
- `/etc/cron.d/arivo-radius-peaks` runs the collector as `www` every minute;
  stderr is `/var/log/arivo-radius-peaks.err` (logrotate configured).
  Live validation found 30 persisted customer peaks; direct dashboard/Home
  data functions both returned `has_record=true`. The current public Android
  release is Arivo ISP Billing `1.1.1+22`; installed clients should be upgraded
  through the validated release manifest/download flow.
- Protected backup directory `/root/arivo-release-backups/20260922-173904/`
  holds the prior Nginx/PHP CLI config, site archive (~32 MB), and read-only
  62-table Panel database SQL snapshot (~14 MB). Restore was NOT rehearsed.
  Do not treat this as evidence that other separately configured DBs are backed up.
- Production CLI PHP 8.3 duplicate extension block/OPcache JIT issue repaired;
  all 11 audited live PHP files passed normal syntax checks.
- Production cache was copied back into next-release `live-traffic.php` and
  `mobile-api.php` invokes its per-customer, single-flight wrapper. Static CI
  prevents accidental cache removal. See `docs/NON_PAYMENT_RELEASE_GATE.md`.
- Production payment webhook authentication is NOT deployed until the SMS
  forwarder's existing secret transmission has been verified; its appconfig
  secret and receipt-ledger unique index do exist. Never disable a live sender
  through an untested auth change.
- `system/mobile/traffic-peaks-migration.sql` creates additive state/peak tables.
- `system/mobile/collect-radius-peaks.php` is CLI-only; run it as a dedicated
  once-per-minute scheduled job after migration and backup on the backend VM.
- Collector reads existing RADIUS `radacct` interim counters. The first sample
  of each session is a baseline; later positive counter deltas are divided by
  elapsed seconds. Reconnects do not invent spikes. Duplicate PPPoE usernames
  are ignored rather than attributed to the wrong customer.
- Panel `mobile-api.php?action=live-traffic` returns `server_peak` for the
  authenticated customer; mobile Home also returns `traffic_peak`.
- Phone foreground graph remains a separate 60-second live visualization.
  Phone-stored history is never used to display the highest speed.
- The peak is the **maximum interval-average speed since deployment**, not an
  instantaneous 1-second maximum. FreeRADIUS interim updates alone cannot
  reconstruct unobserved 1-second spikes.
- Never schedule via an unauthenticated URL. CLI only. Deploy a cron/systemd
  timer after staging tests, with overlap protection and monitoring; verify
  the existing RADIUS interim interval before choosing the schedule.
- Acceptance: record a verified peak; close the phone; generate traffic during
  another accounting interval; reopen/login on another device; see the same
  server peak. Verify off-by-one session gaps and tenant isolation. Do not
  backfill historical rates from cumulative totals.
- Rollback: disable the scheduled collector and restore the previous API/app
  files. Retain the additive tables for later review; do not drop live data.
