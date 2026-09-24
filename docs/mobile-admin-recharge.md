# JM Broadband: mobile admin manual recharge (staged)

Mobile endpoints: `GET mobile-api.php?action=admin-recharge-search&q=...`
and `POST mobile-api.php?action=admin-recharge`.

- Only authenticated staff whose current database role is Admin or SuperAdmin.
  Customer, Agent/reseller and Sales access is denied server-side.
- Searches username/name; active customers with an existing internet plan
  can be renewed without choosing another person's identity from the client.
- Selecting a customer uses their latest plan and router in `tbl_user_recharges`.
  New customers without an assigned plan are not eligible for this flow.
- A typed confirmation, checked independent payment confirmation and current
  admin password are required for every submission.
- Does NOT initiate a bKash/Nagad charge, verify a webhook or debit a balance.
  Legacy `Package::rechargeUser` records the manual transaction and invoice,
  may mark additional bills paid, and attempts the existing router activation.

## Safety and installation

1. Backup live mobile-api.php and current deployment files.
2. Apply `system/mobile/admin-recharge-migration.sql` once, after reviewing
   the target database/table prefix. Existing payment tables stay untouched.
3. Deploy mobile-api.php plus system/mobile/admin-recharge.php, keeping the
   existing `system/mobile/panel-app.php`, `auth-core.php` and billing code.
4. Run PHP syntax checks and customer/reseller authorization-denial checks.
5. Publish the signed Android update only after read-only search succeeds,
   the SQL table exists, and an expressly authorized non-production recharge
   has passed. Do not test against a real customer without approval.

Idempotency: client reuses a secure 128-bit request key on a retry; the
database has a unique key and customer-scoped lock. Pending or uncertain
requests block further recharge for that customer until an administrator
checks the Panel and manually reconciles the request row. Do not simply
delete a pending row or retry with a new key: external PPPoE effects and
invoice creation are not one atomic SQL transaction.
