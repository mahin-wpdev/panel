# Arivo RADIUS usage views — production 2026-09-21

## Actual deployed scope
- Existing FreeRADIUS on 10.10.10.7 writes session accounting into the live Panel `pnux_main.radacct`.
- Authenticated Android Customer Home: monthly Download, Upload, Total and explicit partial-month note. Existing installed v1.0.4 app consumes the API; no reinstall needed.
- Android Admin and Reseller > Customers: per-customer monthly Download, Upload, Total and accuracy note; existing ownership scope is unchanged.
- Arivo ISP Billing Admin > Customers > View: monthly RADIUS usage card per individual customer.
- Arivo ISP Billing Customer > My Account > Profile: same customer's monthly RADIUS usage card.
- The backend reads RADIUS session counters; it does not reset, edit, or invent counters. New RADIUS deployment cannot reconstruct activity from before accounting began.

## Verified
- Existing Panel API server-info HTTP 200 with verified TLS; unauthenticated customer-dashboard HTTP 401.
- Phone screen showed monthly Download, Upload, Total and partial-month note.
- Direct live DB test found 32 recent radacct rows with non-null byte counters at the test time.
- Read-only Admin customer function test: 38 records, zero missing usage fields, about 44 ms.
- Read-only Reseller scoped function test: seven records, zero out-of-scope records.
- All five changed PHP sources parsed successfully; production PHP 8.3 lint passed for all deployed PHP files.
- These CLI smoke tests do not replace an interactive Arivo ISP Billing administrator/reseller browser acceptance test.

## Production rollback
- Backup on VM: `/home/mahin/arivo-usage-views-predeploy-20260921.tar.gz` (all six modified production files; private).
- Older mobile API backup: `/home/mahin/arivo-mobile-pre-radius-20260921.tar.gz`.
- Predeploy copies on the connected Windows PC: `C:\Users\dell\Documents\Arivo-Backups\live-panel-2026-09-21`.
- Restore only the intended files, from a verified backup, after checking live changes. Never run the destructive `install/radius.sql` on `pnux_main`.

## Explicit limitations
- A new accounting installation yields partial-month totals; any session crossing the month boundary reports unavailable rather than claiming exact totals.
- RADIUS one-minute interim updates cannot reconstruct exact one-second historical peaks. The existing phone live graph/peak history is still sampled while the Live screen is in use; a 24/7 server collector is a separate deployment.
- Nothing in this change modifies PPPoE secrets, plans, expiry, billing, recharge or RouterOS AAA configuration.
