# JM Broadband mobile foundation (development only)

This additive PHP API is intentionally isolated from legacy `system/api.php`, payment webhooks and the billing engine. Keep `mahin-wpdev/panel:main` unchanged until the feature branch passes staging integration and security tests.

## Files

- `mobile-api.php` — single JSON endpoint at the panel document path (`?action=server-info|login|refresh|me|logout`)
- `system/mobile/auth-core.php` — legacy-compatible identity and role resolver
- `system/mobile/migration.sql` — session and failed-login tables; run on a **backed-up staging database first**
- `tests/mobile/auth_role_test.php` — pure role resolver checks

## What it does *not* do yet

No traffic/ONU dashboard, payments, support, or full administrator management. The existing customer's password is plaintext and existing staff password is SHA-1 in this codebase. This **legacy compatibility shim is not an authentication hardening or a production approval**. Do not deploy the API without a reviewed secure-credential migration plan, verified staff permission policy, session replay protection, staging regression tests and HTTPS configuration.

Customer, reseller and admin role distinctions are verified from both `tbl_users.user_type` and the **active** `tbl_resellers` association; unsupported staff accounts fail closed. A duplicate valid username across customer and staff is rejected.

## API deployment layout (future staging only)

Place `mobile-api.php` next to `init.php`; place `system/mobile/*` within the existing `system/mobile/` path. Use a valid HTTPS URL such as `https://isp.example.org/panel/mobile-api.php?action=server-info`; the Flutter client accepts the panel base URL.

The mobile API currently checks `$_SERVER['HTTPS']` and port 443; when using a trusted reverse proxy, configure HTTPS correctly in the webserver rather than trusting public `X-Forwarded-Proto` headers.

## Branch-safety

Only upload these files into `feature/mobile-foundation`. Never upload this archive to the `main` branch. The Android source belongs in the separate `mahin-wpdev/jm-broadband-android` repository.
