![PHPNuxBill](install/img/logo.png)

# CHANGELOG

## 2026.09.27 — Arivo ISP Billing next-release

This release turns the PHPNuxBill base into a broader ISP operations platform. The list below is source-audited against current upstream PHPNuxBill master rather than relying only on the inherited README/CHANGELOG.

### Platform, deployment and lifecycle

- Added full Docker Compose stack: MariaDB 11.4, panel, cron worker, bundled WhatsApp, FreeRADIUS, backup service and Caddy gateway.
- Added one-command installer for Ubuntu 22.04/24.04 and Debian 12 with host/resource/network preflight.
- Added first-run setup wizard for company, public endpoint, admin, billing, network, WhatsApp and mobile-release settings.
- Added ordered checksum-tracked migration runner and fresh-install database/RADIUS seeding.
- Added dedicated cron worker for core cron, reminders, ISP usage and OLT synchronization.
- Added full-state backup service for database, uploads, secure data, WhatsApp state and Caddy data/config with daily/weekly/monthly retention.
- Added restore, repair, safe update and uninstall workflows.
- Added pre-update database + full-state backups and health-gated automatic rollback to the previous Git commit/database on failed updates.
- Added normal uninstall that retains data and explicit `--purge-data` destructive removal.

### Mobile app backend and release management

- Added dedicated `mobile-api.php` with server-info, login, refresh, logout, authenticated profile and role-aware access.
- Added customer/admin/reseller mobile sessions, auth-attempt tracking and database-triggered session revocation.
- Added customer dashboard, live traffic, monthly usage and persistent server-owned RADIUS download/upload peak tracking.
- Added admin customer profile, expiry, recharge preview/recharge and ONU operations.
- Added support-ticket workflow with events, replies, status changes and unread notifications.
- Added FCM push-token registration, invalid-token disabling and panel-to-app notification storage.
- Added GitHub-backed stable/beta mobile release flow with tag, APK size, SHA-256, download URL and required-update policy validation.
- Added same-origin APK download endpoint and `[[app_download_link]]` notification placeholder support.

### Reseller system

- Added reseller profiles backed by native Agent users.
- Added reseller-scoped customer ownership, approval workflow and customer username prefixing.
- Added reseller-specific package access and tenant-isolated customer search/list/report views.
- Restricted resellers from changing router/package/ownership/ONU administration outside the intended policy.
- Added immutable recharge-linked earnings with base cost, gross profit, reseller profit and admin profit snapshots.
- Added fixed/percentage reseller profit rules, settlement tracking, reports and invoice access.
- Added reseller dashboard metrics for customers, ONU state, sales and profit.

### OLT / ONU operations

- Added V-SOL Telnet OLT management, encrypted stored credentials and connection testing.
- Added scheduled OLT synchronization and pagination-aware full inventory reads.
- Added ONU status, distance, optical-power, last-seen and movement tracking.
- Added customer ONU assignment/unassignment in panel and mobile admin flows.
- Added guarded single-ONU removal that fails closed unless the ONU is unassigned and live-verified offline.
- Added status/power history and sync logging code paths.

### MikroTik and RADIUS

- Added guided MikroTik onboarding for PPPoE, Hotspot and hybrid modes.
- Added read-only router probe and generated configuration preview before apply.
- Added RouterOS binary backup/export before managed changes.
- Added dedicated restricted panel API user, source-IP-restricted API/API-SSL and managed firewall rules.
- Added RADIUS authentication/accounting, CoA/Disconnect, PPP AAA and interim-update provisioning.
- Added generated rollback script using tagged managed objects.
- Added bundled SQL FreeRADIUS service on 1812/1813/3799.
- Added RADIUS Disconnect-Request/CoA handling in the Radius device adapter.
- Added real CI Access-Accept and accounting persistence tests.

### Automated payment and messaging

- Added bKash Personal SMS-forwarder auto-recharge.
- Added bKash Merchant SMS-forwarder auto-recharge.
- Added Nagad SMS-forwarder auto-recharge.
- Added webhook shared-secret validation, gateway/TrxID replay protection and per-transaction processing locks.
- Added idempotent autorecharge receipt ledger and safe post-recharge error handling.
- Added bundled self-hosted Node.js/Baileys WhatsApp service with internal API-key authentication.
- Added panel WhatsApp status, QR, test-send, reconnect and logout controls plus persistent auth/data/log volumes.
- Kept legacy external WhatsApp URL fallback in the PHP message layer.

### Security and release engineering

- Hardened PHP sessions with strict mode, cookies-only mode, HttpOnly, SameSite=Lax and HTTPS Secure cookies.
- Added Caddy/Apache/Nginx sensitive-path restrictions for config, installer, database, tests, backups and secure files.
- Added installer re-run guard on configured installations.
- Added role/tenant checks for mobile, reseller and ONU operations.
- Added public mobile release digest/size/tag validation.
- Added CI release safety invariants, first-party PHP syntax checks, role authorization tests and ONU fail-closed tests.
- Added clean Docker stack validation, service health checks, public-surface regression tests and repeatable migration tests.
- Added full-state backup -> mutation -> restore verification.
- Added forced failed-update -> automatic rollback verification.
- Added fresh one-command installer smoke test.
- Updated GitHub Actions dependencies to `actions/checkout@v7` and `actions/setup-node@v7` for the Node 24 action runtime.

### New schema objects

- Added `tbl_autorecharge_receipts`.
- Added reseller tables: `tbl_resellers`, `tbl_reseller_packages`, `tbl_reseller_earnings`, `tbl_reseller_settlements`, `tbl_reseller_activity_logs`.
- Added mobile auth/session tables: `tbl_mobile_auth_sessions`, `tbl_mobile_auth_attempts`.
- Added admin recharge requests table.
- Added mobile push tokens and app notifications tables.
- Added support ticket, event and notification tables.
- Added RADIUS peak state and peak history tables.
- Added customer/staff/reseller mobile-session revocation triggers.

### Upstream compatibility retained

- Current upstream ODP management is present.
- Newer upstream custom login-page branding is present.
- Newer upstream PDF invoice and email-attachment support is present.
- Newer upstream parameterized customer/search-query hardening is present, including Arivo-customized customer/reseller query paths.
- Upstream account-expiration rejection is retained in Arivo's custom `rad.php` RADIUS REST flow.

### Audit notes / known follow-up

- Upstream's 2026 forgot-password CSPRNG + verification-attempt lockout patch is not yet ported; Arivo still uses the older `mt_rand()` recovery-code flow.
- Upstream `system/devices/MikrotikVpn.php` is absent while VPN UI templates remain; restore/test the adapter or explicitly retire VPN service support.
- The upstream Arabic language pack is absent.
- OLT/ONU code references custom OLT tables, but this audit did not find their `CREATE TABLE` definitions in the tracked migration manifest or `system/updates.json`; add/verify a clean-install OLT schema migration.
- `radius.php` is currently a debug stub; custom REST RADIUS logic is in `rad.php` while Docker uses SQL FreeRADIUS. Clarify/block the legacy debug endpoint for public releases.
- Custom `rad.php` still contains raw request-derived SQL expressions and should receive a dedicated parameterization/input-safety review.
- Legacy SHA-1 admin/staff password hashing remains inherited technical debt.
- `version.json` still reports the upstream-style `2025.3.20`; define an independent Arivo panel version when formal panel releases begin.

## 2024.10.23

- Custom Balance admin refill Requested by Javi Tech
- Only Admin can edit Customer Requested by Fiberwan
- Only Admin can show password Requested by Fiberwan

## 2024.10.18

- Single Session Admin Can be set in the Settings
- Auto expired unpaid transaction
- Registration Type
- Can Login as User from Customer View
- Can select customer register must using OTP or not
- Add Meta.php for additional information

## 2024.10.15

- CSRF Security
- Admin can only have 1 active session
- Move Miscellaneous Settings to new page
- Fix Customer Online
- Count Shared user online for Radius REST
- Fix Invoice Print

## 2024.10.7

- Show Customer is Online or not
- Change Invoice Theme for printing
- Rearange Customer View

## 2024.9.23

- Discount Price
- Burst Preset

## 2024.9.20

- Forgot Password
- Forgot Username
- Public header template

## 2024.9.13

- Add Selling Mikrotik VPN By @agstrxyz
- Theme Redesign by @Focuslinkstech
- Fix That and this


## 2024.8.28

- add Router Status Offline/Online by @Focuslinkstech
- Show Router Offline in the Dashbord
- Fix Translation by by @ahmadhusein17
- Add Payment Info Page, to show to customer before buy
- Voucher Template
- Change Niceedit to summernote
- Customer can change their language by @Focuslinkstech
- Fix Voucher case sensitive
- 3 Tabs Plugin Manager

## 2024.8.19

- New Page, Payment Info, To Inform Customer, which payment gateway is good
- Move Customer UI to user-ui folder
- Voucher Template
- Change editor to summernote
- Customer can change language

## 2024.8.6

- Fix QRCode Scanner
- Simplify Chap verification password
- Quota based Freeradius Rest
- Fix Payment Gateway Audit

## 2024.8.6

- Fix Customer pppoe username

## 2024.8.5

- Add Customer Mail Inbox
- Add pppoe customer and pppoe IP to make static username and IP
- Add Sync button
- Allow Mac Address Username
- Router Maps

## 2024.8.1

- Show Bandwidth Plan in the customer dashboard
- Add Audit Payment Gateway
- Fix Plugin Manager

## 2024.7.23

- add Voucher Used Date
- Reports page just 1 for all
- fix start date at dashboard
- fix installation parameter

## 2024.7.23

- Add Additional Bill Info to Customer
- Add Voucher only Login, without username
- Add Additional Bill info to Mikrotik Comment
- Add dynamic Application URL For Installation
- Fix Active Customers for Voucher

## 2024.7.15

- Radius Rest API
- Getting Started Documentation
- Only Show new update just once

## 2024.6.21

- Add filter result in voucher and internet plan
- Add input script on-login and on-logout
- Add local ip for pppoe

## 2024.6.19

- new system for device, it can support non mikrotik devices, as long someone create device file
- add local ip in the pool
- Custom Fix Expired Date for postpaid
- Expired customer can move to another Internet Plan
- Plugin installer
- refresh plugin manager cache
- Docker File by George Njeri (@Swagfin)

## 2024.5.21

- Add Maintenance Mode by @freeispradius
- Add Tax System by @freeispradius
- Add Export Customer List to CSV with Filter
- Fix some Radius Variable by @freeispradius
- Add Rollback update

## 2024.5.17

- Status Customer: Active/Banned/Disabled
- Add search with order in Customer list

## 2024.5.16

- Confirm can change Using

## 2024.5.14

- Show Plan and Location on expired list
- Customizeable payment for recharge

## 2024.5.8

- Fix bugs burst by @Gerandonk
- Fix sync for burst by @Gerandonk

## 2024.5.7

- Fix time for period Days
- Fix Free radius attributes by @agstrxyz
- Add Numeric Voucher Code by @pro-cms

## 2024.4.30

- CRITICAL UPDATE: last update Logic recharge not check is status on or off, it make expired customer stay in expired pool
- Prevent double submit for recharge balance

## 2024.4.29

- Maps Pagination
- Maps Search
- Fix extend logic
- Fix logic customer recharge to not delete when customer not change the plan

## 2024.4.23

- Fix Pagination Voucher
- Fix Languange Translation
- Fix Alert Confirmation for requesting Extend
- Send Telegram Notification when Customer request to extend expiration
- prepaid users export list by @freeispradius
- fix show voucher by @agstrxyz

## 2024.4.21

- Restore old cron

## 2024.4.15

- Postpaid Customer can request extends expiration day if it enabled
- Some Code Fixing by @ahmadhusein17 and @agstrxyz

## 2024.4.4

- Data Tables for Customers List by @Focuslinkstech
- Add Bills to Reminder
- Prevent double submit for recharge and renew

## 2024.4.3

- Export logs to CSV by @agstrxyz
- Change to Username if Country code empty

## 2024.4.2

- Fix REST API
- Fix Log IP Cloudflare by @Gerandonk
- Show Personal or Business in customer dashboard

## 2024.3.26

- Change paginator, to make easy customization using pagination.tpl

## 2024.3.25

- Fix maps on HTTP
- Fix Cancel payment

## 2024.3.23

- Maps full height
- Show Get Directions instead Coordinates
- Maps Label always show

## 2024.3.22

- Fix Broadcast Message by @Focuslinkstech
- Add Location Picker

## 2024.3.20

- Fixing some bugs

## 2024.3.19

- Add Customer Type Personal or Bussiness by @pro-cms
- Fix Broadcast Message by @Focuslinkstech
- Add Customer Geolocation by @Focuslinkstech
- Change Customer Menu

## 2024.3.18

- Add Broadcasting SMS by @Focuslinkstech
- Fix Notification with Bills

## 2024.3.16

- Fix Zero Charging
- Fix Disconnect Customer from Radius without loop by @Gerandonk

## 2024.3.15

- Fix Customer View to list active Plan
- Additional Bill using Customer Attributes

## 2024.3.14

- Add Note to Invoices
- Add Additional Bill
- View Invoice from Customer side

## 2024.3.13

- Postpaid System
- Additional Cost

## 2024.3.12

- Check if Validity Period, so calculate price will not affected other validity
- Add firewall using .htaccess for apache only
- Multiple Payment Gateway by @Focuslinkstech
- Fix Logic Multiple Payment gateway
- Fix delete Attribute
- Allow Delete Payment Gateway
- Allow Delete Plugin

## 2024.3.6

- change attributes view

## 2024.3.4

- add [[username]] for reminder
- fix agent show when editing
- fix password admin when sending notification
- add file exists for pages

## 2024.3.3

- Change loading button by @Focuslinkstech
- Add Customer Announcements by @Gerandonk
- Add PPPOE Period Validity by @Gerandonk

## 2024.2.29

- Fix Hook Functionality
- Change Customer Menu

## 2024.2.28

- Fix Buy Plan with Balance
- Add Expired date for reminder

## 2024.2.27

- fix path notification
- redirect to dashboard if already login

## 2024.2.26

- Clean Unused JS and CSS
- Add some Authorization check
- Custom Path for folder
- fix some bugs

## 2024.2.23

- Integrate with PhpNuxBill Printer
- Fix Invoice
- add admin ID in transaction

## 2024.2.22

- Add Loading when click submit
- link to settings when hide widget

## 2024.2.21

- Fix SQL Installer
- remove multiple space in language
- Change Phone Number require OTP by @Focuslinkstech
- Change burst Form
- Delete Table Responsive, first Column Freeze

## 2024.2.20

- Fix list admin
- Burst Limit
- Pace Loading by @Focuslinkstech

## 2024.2.19

- Start API Development
- Multiple Admin Level
- Customer Attributes by @Focuslinkstech
- Radius Menu

## 2024.2.13

- Auto translate language
- change language structur to json
- save collapse menu

## 2024.2.12

- Admin Level : SuperAdmin,Admin,Report,Agent,Sales
- Export Customers to CSV
- Session using Cookie

## 2024.2.7

- Hide Dashboard content

## 2024.2.6

- Cache graph for faster opening graph

## 2024.2.5

- Admin Dashboard Update
  - Add Monthly Registered Customers
  - Total Monthly Sales
  - Active Users

## 2024.2.2

- Fix edit plan for user

## 2024.1.24

- Add Send test for SMS, Whatsapp and Telegram

## 2024.1.19

- Paid Plugin, Theme, and payment gateway marketplace using codecanyon.net
- Fix Plugin manager List

## 2024.1.18

- fix(mikrotik): set pool $poolId always empty

## 2024.1.17

- Add minor change, for plugin, menu can have notifications by @Focuslinkstech

## 2024.1.16

- Add yellow color to table for plan not allowed to purchase
- Fix Radius pool select
- add price to reminder notification
- Support thermal printer for invoice

## 2024.1.15

- Fix cron job for Plan only for admin by @Focuslinkstech

## 2024.1.11

- Add Plan only for admin by @Focuslinkstech
- Fix Plugin Manager

## 2024.1.9

- Add Prefix when generate Voucher

## 2024.1.8

- User Expired Order by Expired Date

## 2024.1.2

- Pagination User Expired by @Focuslinkstech

## 2023.12.21

- Modern AdminLTE by @sabtech254
- Update user-dashboard.tpl by @Focuslinkstech

## 2023.12.19

- Fix Search Customer
- Disable Registration, Customer just activate voucher Code, and the voucher will be their password
- Remove all used voucher codes

## 2023.12.18

- Split sms to 160 characters only for Mikrotik Modem

## 2023.12.14

- Can send SMS using Mikrotik with Modem Installed
- Add Customer Type, so Customer can only show their PPPOE or Hotspot Package or both

## 2023.11.17

- Error details not show in Customer

## 2023.11.15

- Customer Multi Router package
- Fix edit package, Admin can change Customer to another router

## 2023.11.9

- fix bug variable in cron
- fix update plan

## 2023.10.27

- Backup and restore database
- Fix checking radius client

## 2023.10.25

- fix wrong file check in cron, error only for newly installed

## 2023.10.24

- Fix logic cronjob
- assign router to NAS, but not yet used
- Fix Pagination
- Move Alert from hardcode

## 2023.10.20

- View Invoice
- Resend Invoice
- Custom Voucher

## 2023.10.17

- Happy Birthday To Me 🎂 \(^o^)/
- Support FreeRadius with Mysql
- Bring back Themes support
- Log Viewer

## 2023.9.21

- Customer can extend Plan
- Customer can Deactivate active plan
- add variable nux-router to select  only plan from that router
- Show user expired until 30 items

## 2023.9.20

- Fix Customer balance header
- Deactivate Customer active plan
- Sync Customer Plan to Mikrotik
- Recharge Customer from Customer Details
- Add Privacy Policy and Terms and Conditions Pages

## 2023.9.13

- add Current balance in notification
- Buy Plan for Friend
- Recharge Friend plan
- Fix recharge Plan
- Show Customer active plan in Customer list
- Fix Customer counter in dashboard
- Show Customer Balance in header
- Fix Plugin Manager using Http::Get
- Show Some error page when crash
## 2023.9.7

- Fix PPPOE Delete Customer
- Remove active Customer before deleting
- Show IP and Mac even if it not Hotspot

## 2023.9.6

- Expired Pool
Customer can be move to expired pool after plan expired by cron
- Fix Delete customer
- tbl_language removed

## 2023.9.1.1

- Fix cronjob Delete customer
- Fix reminder text

## 2023.9.1

- Critical bug fixes, bug happen when user buy package, expired time will be calculated from last expired, not from when they buy the package
- Time not change after user buy package for extending
- Add Cancel Button to user dashboard when it show unpaid package
- Fix username in user dashboard

## 2023.8.30

- Upload Logo from settings
- Fix Print value
- Fix Time when editing prepaid

## 2023.8.28

- Extend expiration if buy same package
- Fix calendar
- Add recharge time
- Fix allow balance transfer

## 2023.8.24

- Balance transfer between Customer
- Optimize Cronjob
- View Customer Info
- Ajax for select customer

## 2023.8.18

- Fix Auto Renewall Cronjob
- Add comment to Mikrotik User

## 2023.8.16

- Admin Can Add Balance to Customer
- Show Balance in user
- Using Select2 for Dropdown

## 2023.8.15

- Fix PPPOE Delete Customer
- Fix Header Admin and Customer
- Fix PDF Export by Period
- Add pppoe_password for Customer, this pppoe_password only admin can change
- Country Code Number Settings
- Customer Meta Table for Customers Attributess
- Fix Add and Edit Customer Form for admin
- add Notification Message Editor
- cron reminder
- Balance System, Customer can deposit money
- Auto renewal when package expired using Customer Balance


## 2023.8.1

- Add Update file script, one click updating PHPNuxBill
- Add Custom UI folder, to custome your own template
- Delete debug text
- Fix Vendor JS

## 2023.7.28

- Fix link buy Voucher
- Add email field to registration form
- Change registration design Form
- Add Setting to disable Voucher
- Fix Title for PPPOE plans
- Fix Plugin Cache
## 2023.6.20

- Hide time for Created date.
  Because the first time phpmixbill created, plan validity only for days and Months, many request ask for minutes and hours, i change it, but not the database.
## 2023.6.15

- Customer can connect to internet from Customer Dashboard
- Fix Confirm when delete
- Change Logo PHPNuxBill
- Using Composer
- Fix Search Customer
- Fix Customer check, if not found will logout
- Customer password show but hidden
- Voucher code hidden

## 2023.6.8

- Fixing registration without OTP
- Username will not go to phonenumber if OTP registration is not enabled
- Fix Bug PPOE