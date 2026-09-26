[![ReadMeSupportPalestine](https://raw.githubusercontent.com/Safouene1/support-palestine-banner/master/banner-project.svg)](https://s.id/standwithpalestine)

# Arivo ISP Billing

Arivo ISP Billing is a production-oriented ISP billing and operations platform built on top of [PHPNuxBill](https://github.com/hotspotbilling/phpnuxbill).

It keeps the PHPNuxBill billing, voucher, customer, Hotspot, PPPoE, FreeRADIUS, payment-gateway and plugin foundation, then adds a larger operational layer for mobile apps, reseller management, OLT/ONU management, MikroTik provisioning, automated payments, WhatsApp, deployment, backup/restore and release safety.

## Release status

- Main development/release-candidate branch: `next-release`
- Source audit date: 2026-09-27
- Upstream comparison baseline: `hotspotbilling/phpnuxbill` `master` at `fdbd7b84` (2026-08-06)
- The project is still a release candidate until environment-specific financial and destructive network workflows are accepted on staging or an intentionally disposable device.

## Core PHPNuxBill capabilities retained

- Voucher generation and printing
- Hotspot and PPPoE plans
- FreeRADIUS database support
- Multi-router MikroTik support
- Customer self-registration and OTP flows
- Customer balance and automatic renewal
- Payment-gateway and plugin framework
- SMS, WhatsApp and Telegram notification hooks
- Reports, invoices, PDF invoice generation and email attachments
- Custom login-page branding
- ODP (Optical Distribution Point) management
- Parameterized customer/search queries from newer upstream security work

## Arivo additions

### Mobile app and API

- Dedicated `mobile-api.php` backend for Arivo ISP Billing
- Customer, admin/superadmin and reseller mobile roles
- Login, refresh, logout and authenticated `me` flows
- Server-owned mobile auth sessions and auth-attempt tracking
- Automatic mobile-session revocation when customer/staff/reseller state changes
- Customer dashboard API
- Live traffic API
- Monthly usage reporting
- Persistent server-side RADIUS download/upload peak tracking
- Admin customer profile and expiry views
- Admin recharge preview and execution
- Admin ONU list, assign, unassign and guarded removal operations
- Mobile support tickets with replies, status workflow and notifications
- FCM push-token registration and invalid-token disabling
- Panel-to-app notifications
- GitHub-backed stable/beta app release channels
- APK size, SHA-256, tag and release-policy validation
- Same-origin app download endpoint and mandatory-update support

### Reseller system

- Reseller profiles backed by native Agent users
- Reseller-specific customer ownership and visibility
- Customer approval workflow
- Reseller customer username prefixing
- Restricted reseller customer management: router/package/ownership/ONU administration remains admin-only
- Reseller-specific allowed packages
- Immutable recharge-linked earning records
- Base cost, gross profit, reseller profit and admin profit snapshots
- Fixed or percentage profit rules
- Settlement tracking
- Reseller reports and invoice access
- Reseller dashboard with customer, ONU, sales and profit summaries
- Tenant-scoped searches and parameterized queries

### OLT and ONU operations

- V-SOL Telnet OLT adapter
- Encrypted OLT credential handling
- OLT CRUD and connection testing
- Scheduled OLT synchronization
- Pagination-aware ONU inventory reads
- PON/ONU status, distance and optical-power collection
- ONU status and optical-power history
- New/moved ONU tracking
- Customer-to-ONU assignment and unassignment
- Admin mobile ONU operations
- Guarded single-ONU removal only for an unassigned, live-verified offline ONU
- Fail-closed checks for changed MAC/location, online state, unsupported adapters and incomplete CLI responses

### MikroTik onboarding and RADIUS

- Guided MikroTik onboarding UI
- PPPoE, Hotspot and hybrid provisioning modes
- Read-only router probe before apply
- Generated RouterOS configuration preview
- Router binary backup and export before changes
- Dedicated restricted panel API user
- API/API-SSL source-IP restriction
- RADIUS authentication/accounting configuration
- CoA/Disconnect port 3799 configuration
- PPP AAA and interim-update configuration
- Optional PPPoE profile/pool creation
- Managed firewall rules for API and CoA
- Tagged managed objects and generated rollback script
- SQL FreeRADIUS container with ports 1812/1813/3799
- RADIUS Access-Accept and accounting integration tests
- RADIUS Disconnect-Request/CoA handling in the Radius device adapter

### Automated recharge

- bKash Personal SMS-forwarder webhook
- bKash Merchant SMS-forwarder webhook
- Nagad SMS-forwarder webhook
- Webhook shared-secret validation
- Reference/phone based customer resolution
- Amount-to-plan matching
- Transaction receipt ledger
- Unique gateway + TrxID replay protection
- Per-transaction processing lock
- Safe duplicate handling and retry rules
- Protection against reporting a completed recharge as failed only because receipt logging failed

### Bundled WhatsApp service

- Self-hosted Node.js/Baileys WhatsApp service
- Internal API-key authentication
- Panel-managed status dashboard
- QR pairing endpoint
- Test-message flow
- Reconnect and logout actions
- Message persistence/logging
- Configurable send delays and daily send limit
- Persistent WhatsApp auth/data volumes
- Legacy external WhatsApp URL fallback remains available in the PHP message layer

### Deployment and operations

The `docker-compose.yml` stack includes:

- MariaDB 11.4
- PHP/Apache panel
- Cron worker
- Bundled WhatsApp service
- FreeRADIUS
- Full-state backup service
- Caddy gateway

Operational capabilities:

- One-command install on Ubuntu 22.04/24.04 or Debian 12
- First-run setup wizard
- Ordered checksum-tracked migrations
- Fresh-install database/RADIUS seeding
- Automatic daily/weekly/monthly backup retention
- Database, uploads, secure data, WhatsApp state and Caddy-state backups
- Restore workflow
- Repair workflow
- Safe update workflow
- Pre-update database and full-state backups
- Health-gated update
- Automatic rollback to the previous Git commit and database if an update fails
- Normal uninstall that retains data
- Explicit `--purge-data` destructive uninstall

See [installer/README.md](installer/README.md) for operational commands.

## Security and release engineering

- PHP session strict mode and cookies-only mode
- HttpOnly and SameSite=Lax PHP session cookies
- Secure session cookies on HTTPS
- CSRF protection on new admin operations
- Role/tenant checks for mobile and reseller operations
- Webhook authentication for auto-recharge
- Sensitive-path denial in Caddy, Apache and the provided Nginx rules
- Installer re-run protection on configured installations
- Mobile release digest/size/tag validation
- Release CI for PHP syntax, roles, ONU fail-closed guards and static invariants
- Clean Docker-stack integration validation
- Public-surface security regression test
- Real FreeRADIUS authentication/accounting smoke test
- Repeatable migration test
- Full-state backup -> mutation -> restore verification
- Forced failed-update -> automatic rollback verification
- Fresh one-command installer smoke test

## Installation

Fresh supported host:

```bash
curl -fsSL https://raw.githubusercontent.com/mahin-wpdev/panel/next-release/installer/install.sh | sudo bash
```

Minimum host requirements:

- Ubuntu 22.04/24.04 or Debian 12
- Root/sudo access
- 1 CPU or more
- 1 GB RAM or more
- 5 GB free disk or more
- Outbound HTTPS
- Ports 80/tcp, 443/tcp, 1812/udp, 1813/udp and 3799/udp available as required

For automatic HTTPS, point a domain at the host and set `PANEL_SITE_ADDRESS` to that domain.

## Common operational commands

```bash
sudo /opt/jm-panel/installer/update.sh
sudo /opt/jm-panel/installer/repair.sh
sudo /opt/jm-panel/installer/backup.sh
sudo /opt/jm-panel/installer/restore.sh backups/daily/<backup>.tar.gz
sudo /opt/jm-panel/installer/uninstall.sh
```

Use `uninstall.sh --purge-data` only when permanent data destruction is intended.

## Upstream parity audit

The 2026-09-27 source audit compared the tracked `next-release` tree directly with current PHPNuxBill `master`, rather than relying on README or CHANGELOG text.

Summary:

- Raw tree diff: 229 changed paths, including 152 Arivo-added files, 67 modified common files and 10 upstream-only/deleted paths.
- Ignoring whitespace/EOL-only changes: about 205 files, +17,650 / -1,916 lines.
- Of files touched by upstream commits after 2024-10-23, 350 are byte-identical in Arivo, 47 are further customized and 2 are deleted.
- Newer upstream ODP management, custom login branding, PDF invoice/email attachment work and parameterized search changes are present in the Arivo tree.

### Known upstream differences / follow-up items

These are audit findings, not claims that the upstream project is generally better or worse:

1. Upstream's 2026 forgot-password patch uses `random_int()`, `hash_equals()` and a verification-attempt lockout. Arivo's current `system/controllers/forgot.php` still uses the older `mt_rand()` flow. Port this patch before calling password recovery fully hardened.
2. Upstream `system/devices/MikrotikVpn.php` is not present in Arivo. The VPN UI templates remain, so either restore/test the adapter or explicitly retire that feature.
3. The upstream Arabic language pack is not present in Arivo. Restore it if Arabic UI support is required.
4. Arivo OLT/ONU code references `tbl_olts`, `tbl_onus`, PON/history/sync tables, but this audit did not find their `CREATE TABLE` definitions in the tracked migration set or `system/updates.json`. Existing production may already contain them; add/verify a clean-install migration before relying on OLT features on a fresh deployment.
5. The tracked `radius.php` is currently a small debug stub, while the custom REST-style RADIUS logic lives in `rad.php` and the Docker stack uses SQL FreeRADIUS. Clarify or block the legacy debug endpoint before public rollout.
6. The custom `rad.php` contains several raw SQL expressions built from request-derived values. Perform a dedicated parameterization/input-safety review even though the newer upstream search-query hardening is already present elsewhere.
7. Legacy SHA-1 staff/admin password storage is inherited from PHPNuxBill and remains technical debt.
8. `version.json` still carries the upstream-style `2025.3.20` version. Define a separate Arivo panel version/release identifier if independent product releases are required.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Upstream project and attribution

Arivo ISP Billing is derived from PHPNuxBill:

- Upstream repository: https://github.com/hotspotbilling/phpnuxbill
- Original project author/community credits remain applicable to the inherited code.
- Arivo-specific additions are maintained in this repository.

## License

GNU General Public License version 2 or later.

See [LICENSE](LICENSE).
