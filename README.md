# Revolut Merchant Gateway for WHMCS

This module uses WHMCS Remote Input for initial card entry and stores only a Revolut customer/payment-method token. WHMCS remains the billing authority and calls `revolut_capture()` for renewals; it does not create Revolut subscription plans.

## Installation

Copy the `modules/gateways` directory into the WHMCS installation. Activate **Revolut** and configure the secret key, environment, API version, and webhook signing secret.

Set the webhook URL to:

`https://your-whmcs.example/modules/gateways/revolut/webhook.php`

Subscribe to `ORDER_COMPLETED`, `ORDER_FAILED`, `ORDER_CANCELLED`, `ORDER_PAYMENT_FAILED`, and `ORDER_PAYMENT_DECLINED`.

The module creates three small mapping/session tables on first use. No card number or CVV is stored by WHMCS.

## Changes in this build

- Styled responsive embedded checkout with validation, double-submit prevention, processing state, and customer-safe errors.
- Explicit top-window return with link and meta-refresh fallbacks, avoiding a blank iframe callback page.
- Idempotent browser and webhook reconciliation.
- WHMCS 8.2+ `TransactionInformation()` support.
- Imports fees when Revolut returns `payments[].fees` in the order/payment response.

## Fee behaviour

The current Merchant API payment schema includes `fees` entries (`fx` or `acquiring`, amount in minor units, and currency). The module records those fees when present and in the invoice currency. Fees may be absent until Revolut has calculated them; settlement/custom reports also expose `fee_amount` and `processing_fee_amount`, but those asynchronous reports are not queried during checkout.

## Verification

Test in Sandbox first: complete a new-card invoice payment, confirm the return page and Ledger entry, then create a second invoice and run **Attempt Capture** to test the merchant-initiated saved-card path. Finally test a decline and a webhook retry.
