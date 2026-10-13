# Arivo ISP Billing Panel v1.1.9

Auto-recharge now matches references against both account and PPPoE usernames. When the reference does not match, the sender phone can identify the customer only when it maps to one unique account; ambiguous cases fail safely.

Known preflight failures can be retried using the same payment TrxID after customer or package configuration is corrected. Once the actual recharge call begins, the receipt cannot be retried automatically, preventing duplicate charging. A recharge is reported successful only when PHPNuxBill returns a transaction ID. The production bKash shared-payment claim remains in place.

Validation: release safety checks, PHP syntax checks, and customer matching/retry regression passed. Production rollout requires the configured webhook secret and existing receipt ledger; no financial recharge was used as a test.
