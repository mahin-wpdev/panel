# Arivo RADIUS live audit — 2026-09-21

**Live production status: NOT migrated.** Read-only SSH access to MikroTik succeeded; production Panel SSH authentication did not.

## Router verified by SSH
- RouterOS: 7.24.2 stable, hEX S, Router identity: Archer-C80.
- `/ppp aaa`: `use-radius=yes`, `accounting=yes`, `interim-update=5m`.
- `/radius`: one PPP/hotspot RADIUS client pointed to `10.10.10.10`, authentication UDP 1812, accounting UDP 1813, timeout 3 seconds.
- `/radius monitor 0 once`: 482 requests, 0 accepts, 0 rejects, 1413 resends, **482 timeouts**, 0 bad replies. These are cumulative counters at audit time.
- `/radius print detail`: `status="connect:Network unreachable"`.
- `/ppp active print count-only`: 32; PPPoE active: 31.
- `/ppp secret print count-only`: 38, all 38 enabled.
## Server reachability and credentials
- From router, `/tool ping 10.10.10.10 count=2`: 100% packet loss.
- From router, `/tool ping 10.10.10.7 count=2`: 0% packet loss.
- Router login succeeded with the credentials provided by the owner; server `panel@10.10.10.7` authentication did not.
- No evidence that FreeRADIUS is running on `10.10.10.7` or that live `radacct` contains usable accounting records.
- The cloned repository's `radius.php` is a debug stub. Check the live server version before touching REST RADIUS workflows.

## Backup
- A RouterOS `/export` was saved locally to `C:\Users\dell\Documents\Arivo-Backups\mikrotik-pre-radius-2026-09-21.rsc`.
- This is a **redacted configuration export**, not a full binary backup of credential material.
- No router setting or production database was changed during the audit.

## Required next execution gate
1. Obtain authenticated server access and backup the actual live Panel plus its configured Radius database, if any.
2. Determine whether `10.10.10.10` is an offline/retired RADIUS VM and which server will host FreeRADIUS.
3. Set up and verify the replacement RADIUS service *before* redirecting live router auth/accounting.
4. Confirm an end-to-end test PPPoE user: auth, accounting Start/Interim/Stop, speed, pool, expiry, recharge, and rollback.
5. Only then update RADIUS NAS destination or migrate local PPP users in controlled batches.
