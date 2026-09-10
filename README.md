# Revolut Merchant Gateway for WHMCS

An open-source WHMCS payment gateway that accepts card payments through the Revolut Merchant API. WHMCS remains responsible for invoices, renewal dates, retries, upgrades, and recurring billing; Revolut securely stores the payment method and processes each charge.

This is a community-maintained integration and is not an official Revolut or WHMCS product.

## Features

- Secure Revolut Card Field embedded through WHMCS Remote Input
- Checkout styling that follows the active WHMCS primary button color, with a return-to-invoice option
- Saved cards for merchant-initiated recurring charges
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

1. Copy `modules/gateways/` into the WHMCS `modules/gateways/` directory.
2. Copy `whmcs.json` and `logo.svg` to the WHMCS installation root for the enhanced Apps & Integrations listing.
3. In WHMCS, open **Configuration → Apps & Integrations**, locate **Revolut Merchant Gateway**, and activate it.
4. Configure the gateway fields described below.
5. Register the webhook endpoint with Revolut.

The module creates its customer, checkout-session, and transaction-mapping tables on first use. Upgrades remain compatible with the customer mapping schema used by earlier releases.

## Gateway configuration

| Setting | Purpose |
| --- | --- |
| Secret Key | Revolut Merchant API secret key. |
| Public Key | Reserved for supported Revolut checkout features. |
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

## Security

Do not commit API keys, webhook secrets, WHMCS configuration files, database exports, or production logs. Use environment-specific secret storage during development. The supplied `.gitignore` excludes common local secret and log files.

## Support and contributions

- Documentation and source: <https://github.com/cloudtria/revolut-merchant-gateway>
- Issues: <https://github.com/cloudtria/revolut-merchant-gateway/issues>

Contributions and reproducible bug reports are welcome.

## License

MIT. See [LICENSE](LICENSE).
