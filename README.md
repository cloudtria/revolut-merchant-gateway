# WHMCS Revolut Merchant Gateway

Tokenised remote-input gateway for WHMCS using Revolut Merchant API. WHMCS remains the billing engine; Revolut stores payment credentials and performs each invoice charge.

## What this module implements

- Revolut Card Field inside WHMCS remote-input iframe
- First invoice payment + `savePaymentMethodFor: merchant`
- Add-card flow using a zero-amount Revolut order
- WHMCS remote card token storage (no PAN/CVV in WHMCS)
- Merchant-initiated recurring charges from WHMCS cron via `revolut_capture()`
- Full and partial refunds via Revolut order refund
- Signed Revolut webhook verification + reconciliation
- Sandbox and production API endpoints
- API version header, default `2026-08-17`

## Install

Copy the contents of `modules/gateways/` into your WHMCS `modules/gateways/` directory so that these paths exist:

- `modules/gateways/revolut.php`
- `modules/gateways/callback/revolut.php`
- `modules/gateways/revolut/checkout.php`
- `modules/gateways/revolut/webhook.php`
- `modules/gateways/revolut/lib/*`

In WHMCS go to **Configuration > Apps & Integrations > Payments > Payment Gateways** (menu wording varies by WHMCS version), activate **Revolut**, and configure:

- Secret Key
- Public Key (stored for future extensions; Card Field token flow does not require it server-side)
- Environment = Sandbox initially
- API Version = `2026-08-17`
- Webhook Signing Secret
- Checkout SDK URL (default `https://merchant.revolut.com/embed.js`)

The module creates `mod_revolut_customers` on first use to map one WHMCS client to one Revolut customer.

## Webhook

Configure this endpoint in Revolut:

`https://hosting.cloudtria.com/modules/gateways/revolut/webhook.php`

Recommended events:

- `ORDER_COMPLETED`
- `ORDER_FAILED`
- `ORDER_CANCELLED`
- `ORDER_PAYMENT_DECLINED`
- `ORDER_PAYMENT_FAILED`

Copy the webhook signing secret into the gateway configuration. The handler verifies `Revolut-Request-Timestamp` within 5 minutes and validates `Revolut-Signature` with HMAC-SHA256.

## Test plan

1. Enable Sandbox.
2. Create a low-value WHMCS invoice and choose Revolut.
3. Pay using a Revolut sandbox test card and complete any 3DS flow.
4. Confirm invoice becomes Paid and the client has a remote card Pay Method.
5. Create another unpaid invoice for the same client and use the WHMCS admin/cron capture path. Confirm it charges without customer interaction.
6. Test a declined card and confirm WHMCS records a declined capture.
7. Test partial then full remaining refund from the WHMCS transaction.
8. Confirm Gateway Log contains IDs/state but no secret, PAN, or CVV.
9. Confirm webhook replay with an old timestamp or altered signature is rejected.
10. Only then switch Environment to Production and enter production keys/webhook secret.

## Important production notes

- Billing address is supplied to Revolut Card Field. Current Revolut guidance requires it for production card payments.
- The saved-card token is `rv1:<revolut_customer_id>:<payment_method_id>`.
- Revolut subscription objects are intentionally not used. WHMCS controls invoice generation, renewal schedules, retries, upgrades, domains, setup fees, and cancellations.
- `remoteupdate()` intentionally asks the customer to add a replacement card because stored card credentials should not be edited in place.
- Browser success is never trusted by itself. The WHMCS callback retrieves the Revolut order server-side and requires it to be completed before applying payment.

## PHP requirements

- A WHMCS-supported PHP version
- cURL extension
- Standard WHMCS database/Capsule facilities

## Files to review before production

This package is intended as a strong implementation baseline, but it has not been executed inside your specific WHMCS installation or Revolut account. Run the sandbox test plan and inspect WHMCS Gateway Logs before production enablement.
