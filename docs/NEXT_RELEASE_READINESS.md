# Next-release readiness — Panel + Arivo (2026-09-22)

## Scope / isolation
This branch is a **release candidate**, not an authorization to alter the running
server. The Flutter companion lives in a separate repository, also on branch
`next-release`. No database, RouterOS, OLT, or deployed PHP changes are part
of these commits.

## Verified in the audit
- OLT pager fix was confirmed on the running server by two new `synced 26`
  entries after installation; the older 22 was only the first CLI page.
- Flutter tests: 8 passed; `flutter analyze --no-pub`: no issues.
- Fresh signed Android 1.0.4+5 APK build succeeded locally and apksigner
  verified APK Signature Scheme v2; release credentials were not committed.
- Local PHP syntax parser accepted the touched first-party PHP, payment, OLT
  and installer files. CI must separately validate with PHP 8.3 `php -l`.
- Added automatic release safety checks and branch-targeted CI definitions.

## Fixes prepared ONLY on next-release
- Do not track runtime payment and SMS logs. Existing local log files remain
  untracked and are ignored. Existing public *Git history* still contains
  earlier revisions and must be addressed separately.
- Remove the unreferenced legacy `radius_backup.php` endpoint.
- All six installer PHP entry points now refuse execution when a `config.php`
  exists at the Panel root or its immediate parent, preventing destructive
  `install/step4.php` reinstallation on a configured instance.
- Include a sample Nginx deny configuration for `/panel/install/`, sensitive
  file extensions and the legacy endpoint. **It is not applied to production.**
- Keep read-only ONU paging checks, separate OLT/Panel adapters, and
  single-offline-ONU removal protections.

## Blockers BEFORE public rollout / production acceptance
1. **Production web server needs hardening.** Audit observed unauthenticated
   HTTP 200 for `/panel/install/index.php`,
   `/panel/install/radius.sql` and one payment webhook `.log`.
   Install the Nginx restrictions and re-test from an unauthenticated browser;
   do not upload or run destructive installer SQL on existing data.
2. **Historic public payment/SMS log exposure.** Removing files on a new
   branch does not erase existing public commits or existing live log contents.
   Perform authorized incident review, redaction/history strategy and rotate
   any exposed secrets. Do not force-push old branches without coordinating
   with collaborators.
3. **Critical workflows require non-production integration tests:** personal
   bKash, merchant bKash and Nagad success/failure/duplicate/incorrect-ref
   handling; recharge receipt idempotency and PPPoE enable/expiry; reseller
   price/profit snapshots, tenant isolation, customer visibility; RADIUS
   auth/accounting across reconnection and month boundary.
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
