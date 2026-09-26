# Non-payment release gate — JM Broadband Panel + Android

Audit date: 2026-09-22 (Asia/Dhaka). This is a scoped readiness record, not
authorization to replace the whole production Panel directory.

## Scope
Customer Home, monthly recorded usage, live PPPoE graph, server-owned RADIUS
highest-speed history; read-only admin/reseller/customer/ONU listings; OLT
sync/identity safety, app auth and deployment regression checks.

**Out of scope:** bKash/Nagad webhooks, payments, recharge, invoicing, reseller
profit/settlement, PPPoE activation/deactivation, and real ONU deletion.
Do not make a claim that the entire ISP billing system is release-ready based
on this document.

## Acceptance evidence
- Owner confirmed live app UI now shows correct highest-speed data.
- Production RADIUS peak state/peaks tables and once-per-minute CLI collector
  are active. Direct Panel reader and Home functions returned a stored record.
  The audited DB held 30 peak records. Peak = maximum observed RADIUS interval
  average since collector rollout, not instantaneous speed or earlier history.
- Production read-only reseller check: 7 customer rows, 5 ONU rows; zero
  cross-reseller customer rows. No PPPoE/router passwords in these responses.
- Monthly accounting scan: 30 active customers return a **partial-month**
  usage value and 7 return unavailable because complete counters are absent.
  Do not market these readings as full-calendar-month totals.
- Android 1.0.5+6 Debug APK updated the existing 1.0.4 Debug install in place;
  original Android first-install timestamp remained unchanged. Owner confirmed
  current live UI. This is **not** public release signing acceptance.
- PHP 8.2: 8 role cases, 9 offline ONU negative safety cases, mobile source
  lint, static release invariant checks; Android: 12 Flutter tests, 0 analyzer
  issues. GitHub PHP 8.3 and Android CI must pass after each final push.
- The production live endpoint uses `jm_live_snapshot_cached` to avoid a
  MikroTik request per UI poll. Next-release branch retains this code and
  has assertions preventing accidental removal.

## Android certificate migration (important)
Phone's previous 1.0.4 was Android Debug signed:
`02d0510cfa694beab96f75be587244ac051f769a3e0364743457f0238d11f501`.
JM Broadband's 1.0.5+6 public APK is independently release-signed:
`a6ed4b62afa6e2e50afff1679bf67645927b28e9a5a017d56d9ad7bf07e89ca8`.
Android cannot upgrade either signer to the other under the same application
ID. Do not offer `adb uninstall -k` or a forced overwrite as a workaround.
Preserve the installed Debug build for testing. A transition to the public
release needs an explicitly accepted reinstall/re-login path after recording
the customer's required local data, or a separately planned new package ID.
Server-side speed peaks remain in the Panel DB and do not depend on the phone.
Never distribute an APK signed with the Debug key as the public build.

## Deferred verification / release limitations
- Physical `offline-onu del` remains **hardware-unverified**: the nine negative
  guard tests do not prove the command against a retired ONU. No live removal
  was attempted. Do not include destructive ONU deletion in this release.
- RADIUS reconnect and cross-month byte attribution cannot be treated as fully
  end-to-end validated from the peak tests; monthly UI intentionally says
  unavailable when exact accounting is not provable.
- The branch is not a complete byte-identical production image. Avoid a bulk
  copy or database migration of the whole release candidate. Production
  backups: `/root/arivo-release-backups/20260922-173904/`; restore has
  not been rehearsed.
- Historical repository/payment log exposure and payment acceptance are a
  separate security/release gate, intentionally not altered in this work.
