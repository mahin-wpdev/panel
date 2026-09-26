# Arivo / phpNuxBill RADIUS migration — staged cutover

Status: **NOT DEPLOYED to production.** This is a version-controlled rollout and rollback plan. Do not run the old `install/radius.sql` against an existing database: it contains DROP TABLE statements.

## Current verified constraints
- Production Panel host: 10.10.10.7; RouterOS PPPoE NAS: 10.10.10.1.
- Both SSH ports are reachable, but non-interactive SSH authentication failed from the authorized Windows host.
- The cloned Panel repo is older than the known live server revision. Never overwrite the live Panel from this clone wholesale.
- The clone has `system/devices/Radius.php`, optional separate Radius PDO connection in `init.php`, and a `radacct` schema.
- The clone's `radius.php` is only a debug stub. Confirm whether live production differs before switching to any REST Radius integration.

## 0. Backup and read-only preflight on the *live server*
1. Back up RouterOS with an encrypted export/backup and keep the restoration path working.
2. Back up production Panel files and both Panel and RADIUS MySQL/MariaDB databases.
3. Record active PPPoE session counts, per-customer PPP profile/speed/pool, billing status and existing NAS/RADIUS settings.
4. Run `php system/mobile/arivo-radius-preflight.php` using the **production-matched** version. This script prints counts and schema flags, not secrets.
5. Verify the production Radius SQL connection and `radacct` schema. Do not assume Panel and Radius share one database.
## 1. Start with accounting only
- Set up/verify the FreeRADIUS service, SQL accounting module, NAS entry for RouterOS LAN source IP, and private RADIUS shared secret.
- Restrict UDP 1812/1813 to the router's **actual NAS source address**, not the public Internet.
- Configure the RouterOS RADIUS client for PPP accounting and preserve existing local PPP secrets. Do not delete or mass-disable customer secrets during the accounting trial.
- Set a sustainable PPP AAA interim-update (for example, 5 minutes) once capacity is checked; **not 1 second**.
- Use one test PPPoE session: verify Start → Interim-Update → Stop are stored with a stable session ID and nondecreasing 64-bit octets.
- Cross-check download/upload against router interface TX/RX with a deliberate direction-specific test. Do not guess RADIUS direction by field name alone.
- Compare the customer session, assigned IP, rate limit, expiry, and auto-recharge behavior with the pre-migration baseline.

## 2. Move authentication in controlled batches
- Ensure a Radius radcheck/radreply/radusergroup record is created and updated for the test customer by the existing phpNuxBill Radius device.
- Confirm successful authentication **through RADIUS**, not just continued local PPP secret authentication. RouterOS prefers a matching local PPP secret.
- Check package speed, expiration, blocking after expiry, payment-triggered re-enable, resend/duplicate payment, reseller-specific pricing, and customer ownership.
- Only after a test customer passes, migrate other secrets in controlled batches; retain a working management login and revert path.
- Do not change WAN PCC, NAT, VLAN/OLT, or PPPoE addresses as part of accounting migration.

## 3. Separate one-second history from RADIUS accounting
- A server-side collector reads PPPoE interface counters every second; the mobile app reads this collector for the last 60s graph and rolling 1h download/upload peak.
- Bind collector records to customer ID + router + session identity; prevent cross-customer leaks, duplicates, negative counter reset deltas, and stale data.
- Sampled **speed** is not a reliable monthly byte total. Retain raw 60s points and a rolling-hour peak; avoid unbounded 1-second database growth.
- RADIUS supplies session accounting and longer-term consumption. For monthly boundaries crossed by an active session, keep dated counter snapshots and mark imprecise boundaries clearly; a single radacct cumulative row cannot reconstruct the exact month.
## 4. Acceptance / rollback
- Confirm RADIUS client status and no auth/accounting retries; compare online PPPoE counts before/after.
- Confirm a customer can reconnect after payment/expiry changes and still gets the same router, profile, speed and pool.
- Confirm at least one real accounting Interim-Update is written and that direction, count and session ID agree with the NAS.
- Test graph while phone is closed: the server collector must continue sampling. If it does not, do not claim server-managed history is working.
- Confirm access isolation for two distinct customer accounts.
- On regression, restore previous PPP AAA/RADIUS client configuration and local secrets; do not drop RADIUS tables. Restore files/DB from backup only if necessary.
- Keep all cutover records dated. The new Arivo mobile monthly read path is **source-only** until the production server receives compatible code and the database is verified.

### Current blocker
The remote desktop connector can reach RouterOS and Panel over TCP, but it cannot authenticate to either over SSH with available non-interactive credentials. Production RADIUS/PPP configuration, data migration, systemd collector, cron and API deployment have **not** been executed or verified.
