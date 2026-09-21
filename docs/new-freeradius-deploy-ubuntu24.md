# New FreeRADIUS deployment on the existing Ubuntu Panel server

**Not deployed as of 2026-09-21.** This file is a guarded execution plan for an operator with authenticated sudo access to 10.10.10.7. The supplied `panel` login works on MikroTik 10.10.10.1 but was rejected by Ubuntu SSH and Proxmox.

## Verified facts
- RouterOS 7.24.2 / hEX S: PPP AAA `use-radius=yes accounting=yes interim-update=5m`.
- Old NAS target 10.10.10.10 is unreachable; 482 of 482 observed requests had timed out.
- 38 enabled local PPP secrets, 31 active PPPoE sessions. Do not mass-remove/reconnect them.
- The production Panel version differs from the older Windows clone. Check live files and config before deploying mobile changes.

## 1. Server backup and inventory (sudo on the live Ubuntu VM)
- Confirm available RAM/disk and aaPanel, Nginx/PHP, DB health, timezone and backups.
- Back up `/www/wwwroot/27.147.201.165/panel`, actual Panel database, and any existing RADIUS database.
- Inspect the *live* `config.php` and `init.php` without copying credentials into logs or version control.
- Inspect `ss -lunp`, `systemctl status freeradius`, `dpkg -l`, and `mysql SHOW DATABASES`. If another service owns UDP 1812/1813, do not replace it blindly.

## 2. Isolated FreeRADIUS and SQL
- On supported Ubuntu 24.04, install distro packages `freeradius` and `freeradius-mysql` with `apt`; install MariaDB only if no suitable SQL server already exists.
- Use a dedicated RADIUS SQL database and least-privilege user, with independently generated random DB and NAS secrets; restrict secret files to root/freerad.
- Use the *installed package's* `/etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql` **only for a freshly created empty RADIUS database**. Do not run phpNuxBill's destructive `install/radius.sql` on existing data.
- Configure `mods-available/sql` for MariaDB and enable `mods-enabled/sql`; enable SQL in authorize, accounting, post-auth and required inner-tunnel paths after checking the installed version's configuration.
- Add exactly the router's observed RADIUS source address as NAS client with the same shared secret; restrict UDP 1812/1813 to that address on the server's LAN firewall.
- Check FreeRADIUS configuration with `freeradius -XC`; run a separate `freeradius -X` diagnostic only during an approved maintenance window and **never paste debug output containing usernames/secrets**.
- Check a test SQL user via an appropriate local PAP test. Then verify a real PPPoE test identity, assigned IP, speed, expiry and billing outcome before moving any customer.

## 3. Integrate phpNuxBill and preserve service
- Confirm production's actual Radius driver, SQL connection, and customer provisioning hooks. The Windows clone's `radius.php` is a debug stub; do not overwrite production with it.
- Account for phpNuxBill-specific SQL columns such as `radgroupreply.plan_id`; add missing columns only via a reviewed additive migration.
- Point production's **separate** Radius SQL connection to the new database. Arivo's branch `feature/monthly-bandwidth-usage` includes the read-path fix and preflight script.
- Test one customer from payment → recharge → RADIUS group/rate-limit → PPPoE login → expiry/disable and re-enable.

## 4. Router cutover and rollback
- Record `/radius print detail`, `/ppp aaa print`, PPP profiles/secret counts and active count. Keep existing local secrets and the RouterOS export.
- Switch RADIUS destination from dead 10.10.10.10 to **verified** new service 10.10.10.7 only after UDP auth/accounting responses and server log verification.
- With local secrets still enabled, do not claim RADIUS *authentication* was migrated: RouterOS checks a matching local secret first. Move the first test account only after confirming credentials, group, speed, profile, pool and re-connect.
- Verify RADIUS Start → Interim-Update → Stop, octets/gigawords, idle and month-boundary snapshots; confirm all 31 pre-cutover PPPoE sessions are unaffected.
- On regression, revert the saved destination and local-secret settings; do not drop or reset accounting tables.

## 5. One-second traffic history is not FreeRADIUS accounting
- The 60-second graph and 1-hour exact sampled speed peak require a continuously running server collector querying the per-PPP interface.
- RADIUS `interim-update=5m` is appropriate for bulk accounting; it cannot reconstruct the peak of every one-second interval.
- No server collector, FreeRADIUS daemon, or production backend has been installed by the current Windows-only access path.
