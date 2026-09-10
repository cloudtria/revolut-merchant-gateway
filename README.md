# Revolut Merchant Gateway for WHMCS

An open-source WHMCS payment gateway that accepts card payments through the Revolut Merchant API. WHMCS remains responsible for invoices, renewal dates, retries, upgrades, and recurring billing; Revolut securely stores the payment method and processes each charge.

This is a community-maintained integration and is not an official Revolut or WHMCS product.

## Features

- Secure Revolut Card Field embedded through WHMCS Remote Input
- Optional Revolut Pay and device-eligible Apple Pay / Google Pay buttons
- Checkout styling that follows the active WHMCS primary button color, with a return-to-invoice option
- Saved cards and Revolut Pay methods for merchant-initiated recurring charges
- Account-level creation and replacement of saved Revolut payment methods
- WHMCS-managed billing rather than Revolut subscription plans
- Full and partial refunds with idempotent retry handling
- Signed webhook verification and payment reconciliation
- Revolut transaction-fee recording when fee data is available
- WHMCS 8.2+ transaction-information modal support
- Configurable customer-visible payment and refund references
- No card number or CVV stored by the module

## Requirements

- A supported WHMCS installation with Remote Input gateway support
- PHP with cURL and JSON extensions
- A Revolut Business Merchant account
- Revolut Merchant API secret key and webhook signing secret
- HTTPS on the WHMCS installation

## Installation

1. Copy the release package's `modules/gateways/` directory into the WHMCS installation's `modules/gateways/` directory, preserving its structure.
2. Confirm `modules/gateways/revolut/whmcs.json` and `modules/gateways/revolut/revolut-logo.png` were uploaded. WHMCS uses these for the enhanced Apps & Integrations listing.
3. In WHMCS, open **Configuration → Apps & Integrations**, locate **Revolut Merchant Gateway**, and activate it.
4. Configure the gateway fields described below.
5. Register the webhook endpoint with Revolut.

The module creates its customer, checkout-session, and transaction-mapping tables on first use. Upgrades remain compatible with the customer mapping schema used by earlier releases.

## Gateway configuration

| Setting | Purpose |
| --- | --- |
| Secret Key | Revolut Merchant API secret key. |
| Public Key | Revolut browser-safe public API key. Required for Revolut Pay and wallets. |
| Enable Revolut Pay | Displays Revolut Pay and requests permission for future merchant-initiated charges. |
| Enable Apple Pay / Google Pay | Displays a wallet button when the device and browser support one. Wallets are used for one-time invoice payments. |
| Environment | `Sandbox` or `Production`. Keys and webhooks must match this environment. |
| API Version | Merchant API version header. The packaged default is `2026-08-17`. |
| Webhook Signing Secret | Secret returned when the Revolut webhook is created or retrieved. |
| Checkout SDK URL | Revolut Checkout embed script. Normally leave the default unchanged. |
| Payment Reference | Customer-visible reference for payment orders. |
| Payment Description | Customer-visible description for payment orders. |
| Refund Reference | Customer-visible reference for refund orders. |
| Refund Description | Customer-visible description for refund orders. |
| Debug Logging | Writes non-sensitive diagnostic information to the WHMCS Gateway Log. |

Reference and description settings accept `{invoice_id}`, `{client_id}`, `{amount}`, `{currency}`, and `{company_name}`.

Example configuration:

```text
Payment Reference:          ExampleHost-Invoice-{invoice_id}
Payment Description:        ExampleHost invoice #{invoice_id}
Refund Reference:           ExampleHost-Refund-{invoice_id}
Refund Description:         Refund for ExampleHost invoice #{invoice_id}
```

The invoice and client IDs remain in Revolut metadata for server-side correlation regardless of the customer-visible format.

## Revolut Pay and wallets

Revolut Pay and wallet buttons are opt-in so the established card checkout remains unchanged until they are enabled. Generate or retrieve the browser-safe public API key in Revolut Business, enter it in **Public Key**, then enable the desired options in the WHMCS gateway configuration.

Revolut Pay is initialised with `savePaymentMethodForMerchant: true`. When Revolut returns a saved `revolut_pay` method, the module stores a typed remote token so WHMCS can use it for later merchant-initiated recurring charges. Existing `rv1` saved-card tokens remain compatible.

Apple Pay and Google Pay are offered only for invoices with an amount due and only when Revolut reports that the customer's device and browser can make the payment. These wallet payments do not replace the customer's recurring WHMCS Pay Method.

Google Pay requires no additional domain step. Apple Pay requires production testing and domain registration. Follow Revolut's current [Apple Pay and Google Pay web setup](https://developer.revolut.com/docs/guides/merchant/accept-payments/online-payments/apple-pay-google-pay/web), including serving Apple's current association file from `/.well-known/apple-developer-merchantid-domain-association` and registering the billing domain with Revolut. Revolut states that Apple Pay is unavailable in Sandbox.

## Webhook configuration

Register this endpoint in the matching Revolut environment:

```text
https://billing.example.com/modules/gateways/revolut/webhook.php
```

Subscribe to `ORDER_COMPLETED`, `ORDER_FAILED`, `ORDER_CANCELLED`, `ORDER_PAYMENT_FAILED`, and `ORDER_PAYMENT_DECLINED`.

```Example Webhook Subscription
curl -sS -X POST "https://merchant.revolut.com/api/webhooks" \
  -H "Authorization: Bearer ${REVOLUT_SECRET_KEY}" \
  -H "Revolut-Api-Version: 2026-08-17" \
  -H "Content-Type: application/json" \
  -d '{                                               
    "url": "https://$WHMCS_URL/modules/gateways/revolut/webhook.php",
    "events": [                         
      "ORDER_COMPLETED",
      "ORDER_FAILED",
      "ORDER_CANCELLED",
      "ORDER_PAYMENT_DECLINED",
      "ORDER_PAYMENT_FAILED"
    ]
  }' | jq
```

Copy the returned signing secret into **Webhook Signing Secret** in WHMCS. The endpoint validates Revolut's HMAC-SHA256 signature and rejects timestamps outside a five-minute tolerance.

## Billing architecture

For an initial payment, the customer enters card details in Revolut's PCI-hosted Card Field. The module requests that the method be saved for the merchant and stores only the resulting Revolut customer and payment-method identifiers in the WHMCS Pay Method.

For renewals, WHMCS calls `revolut_capture()` with the invoice amount. The module creates a new Revolut order and charges the saved method as a merchant-initiated transaction. It does not create or maintain Revolut subscription plans.

When a customer adds or replaces a method under **Account → Payment Methods**, the module uses Revolut's zero-amount authorisation flow. No charge is taken. WHMCS represents every Remote Input method as a card; because a saved Revolut Pay account has no card expiry, the module supplies a future display-only expiry while the Revolut token remains authoritative.

## Refunds

WHMCS calls `revolut_refund()` for full or partial refunds. The module resolves the original Revolut order, submits an idempotent refund request, and returns the refund order identifier to WHMCS. Revolut processes refunds asynchronously, so the Merchant portal can show a refund as pending before settlement completes.

## Transaction fees

The current Merchant API payment schema can return `fees` entries for acquiring and foreign-exchange fees. When a returned fee is in the invoice currency, the module records it in WHMCS. Fee information may be absent until Revolut calculates it. Settlement and custom reports can provide more comprehensive fee data, but this module does not run asynchronous report reconciliation.

## Testing

Use Revolut Sandbox before enabling production keys:

1. Complete an invoice using **Enter New Card**.
2. Confirm the customer returns to the invoice and the payment appears in the WHMCS ledger.
3. Create another invoice using the saved Revolut card and run **Attempt Capture**.
4. Test a decline and confirm the customer-facing error and Gateway Log entry.
5. Test a partial refund, followed by a full refund on a separate transaction.
6. Replay a signed webhook and confirm no duplicate transaction is created.
7. Enable Revolut Pay with a matching public key, complete a payment, and test a later **Attempt Capture** against the saved Revolut Pay method.
8. On an eligible production device, complete an Apple Pay or Google Pay invoice and confirm the existing recurring Pay Method is unchanged.
9. Add and replace a Revolut method under **Account → Payment Methods** and confirm that no payment is charged.

## Security

Do not commit API keys, webhook secrets, WHMCS configuration files, database exports, or production logs. Use environment-specific secret storage during development. The supplied `.gitignore` excludes common local secret and log files.

## Support and contributions

- Documentation and source: <https://github.com/cloudtria/revolut-merchant-gateway>
- Issues: <https://github.com/cloudtria/revolut-merchant-gateway/issues>

Contributions and reproducible bug reports are welcome.

## License

MIT. See [LICENSE](LICENSE).
