AUTO RECHARGE FIX - 2026-09-19

Supported gateways:
1. bKash Personal   -> bkash.php
2. bKash Merchant   -> bkash_merchant.php
3. Nagad            -> nogod.php

Main fixes:
- bKash Merchant is now accepted by autoRechargeUser().
- Merchant webhook no longer writes false SUCCESS logs.
- All webhook logs show the actual engine result/message.
- transaction_id values such as INV-110 are supported safely.
- Legacy INT transaction_id columns have a numeric fallback, but migration.sql
  should still be run so the full transaction reference is stored.
- Receipt-save failures after an already-completed recharge no longer report the
  recharge itself as failed or make it retryable.
- Unrelated receipt-insert DB errors are no longer silently treated as duplicate payments.

Recommended database migration:
Run migration.sql once in phpMyAdmin / MySQL.

DYNAMIC GATEWAY IMPROVEMENT
---------------------------
autorecharge.php is now gateway-agnostic. Do not maintain an allowed-gateway
list in the shared engine. A future webhook/parser only needs to pass a
non-empty gateway name, for example:

    'gateway' => 'Rocket'

or:

    'gateway' => 'Upay'

Existing bKash, bKash Merchant and Nagad integrations continue to work.
