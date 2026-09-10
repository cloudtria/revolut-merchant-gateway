<?php

if (!defined('WHMCS')) die('This file cannot be accessed directly');

use WHMCS\Billing\Currency;
use WHMCS\Billing\Payment\Transaction\Information;
use WHMCS\Carbon;
use WHMCS\Database\Capsule;

require_once __DIR__ . '/revolut/lib/Helpers.php';
revolut_require_library();

function revolut_MetaData()
{
    return ['DisplayName' => 'Revolut Merchant Gateway', 'APIVersion' => '1.1'];
}

function revolut_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Revolut'],
        'secretKey' => ['FriendlyName' => 'Secret Key', 'Type' => 'password', 'Size' => '80'],
        'publicKey' => ['FriendlyName' => 'Public Key', 'Type' => 'password', 'Size' => '80', 'Description' => 'Required when Revolut Pay or Apple Pay / Google Pay is enabled. This key is designed for browser use.'],
        'enableRevolutPay' => ['FriendlyName' => 'Enable Revolut Pay', 'Type' => 'yesno', 'Description' => 'Offer Revolut Pay and save authorised methods for WHMCS-managed recurring charges. Requires the Public Key.'],
        'enableWallets' => ['FriendlyName' => 'Enable Apple Pay / Google Pay', 'Type' => 'yesno', 'Description' => 'Offer available wallets for one-time invoice payments. Requires the Public Key; Apple Pay also requires domain registration.'],
        'environment' => ['FriendlyName' => 'Environment', 'Type' => 'dropdown', 'Options' => ['sandbox' => 'Sandbox', 'production' => 'Production'], 'Default' => 'sandbox'],
        'apiVersion' => ['FriendlyName' => 'API Version', 'Type' => 'text', 'Default' => '2026-08-17'],
        'webhookSecret' => ['FriendlyName' => 'Webhook Signing Secret', 'Type' => 'password', 'Size' => '80'],
        'checkoutSdkUrl' => ['FriendlyName' => 'Checkout SDK URL', 'Type' => 'text', 'Default' => 'https://merchant.revolut.com/embed.js'],
        'paymentReferenceFormat' => ['FriendlyName' => 'Payment Reference', 'Type' => 'text', 'Size' => '60', 'Default' => 'Invoice-{invoice_id}', 'Description' => 'Customer-visible Revolut order reference. Tokens: {invoice_id}, {client_id}, {amount}, {currency}, {company_name}.'],
        'paymentDescriptionFormat' => ['FriendlyName' => 'Payment Description', 'Type' => 'text', 'Size' => '60', 'Default' => 'Invoice #{invoice_id}', 'Description' => 'Customer-visible Revolut payment description. Supports the same tokens as Payment Reference.'],
        'refundReferenceFormat' => ['FriendlyName' => 'Refund Reference', 'Type' => 'text', 'Size' => '60', 'Default' => 'Refund-{invoice_id}', 'Description' => 'Customer-visible Revolut refund reference. Supports the same tokens as Payment Reference.'],
        'refundDescriptionFormat' => ['FriendlyName' => 'Refund Description', 'Type' => 'text', 'Size' => '60', 'Default' => 'Refund for invoice #{invoice_id}', 'Description' => 'Customer-visible Revolut refund description. Supports the same tokens as Payment Reference.'],
        'debug' => ['FriendlyName' => 'Debug Logging', 'Type' => 'yesno', 'Description' => 'Log non-sensitive Revolut responses while testing.'],
    ];
}

function revolut_get_or_create_customer(RevolutClient $client, array $params)
{
    revolut_ensure_tables();
    $clientId = (int) $params['clientdetails']['id'];
    $columns = revolut_customer_columns();
    $row = Capsule::table('mod_revolut_customers')->where($columns['client'], $clientId)->first();
    if ($row && !empty($row->{$columns['customer']})) return $row->{$columns['customer']};
    $customer = $client->post('/api/customers', [
        'email' => (string) $params['clientdetails']['email'],
        'full_name' => trim(($params['clientdetails']['firstname'] ?? '') . ' ' . ($params['clientdetails']['lastname'] ?? '')),
    ]);
    Capsule::table('mod_revolut_customers')->updateOrInsert([
        $columns['client'] => $clientId,
    ], [
        $columns['customer'] => $customer['id'],
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    return $customer['id'];
}

function revolut_create_order(RevolutClient $client, array $params, $customerId)
{
    $invoiceId = isset($params['invoiceid']) ? (int) $params['invoiceid'] : 0;
    $reference = revolut_order_reference($params['paymentReferenceFormat'] ?? '', $params, 'Invoice-{invoice_id}');
    $description = revolut_order_reference($params['paymentDescriptionFormat'] ?? '', $params, 'Invoice #{invoice_id}');
    return $client->post('/api/orders', [
        'amount' => revolut_minor_units($params['amount'] ?? 0, $params['currency']),
        'currency' => strtoupper($params['currency']),
        'capture_mode' => 'automatic',
        'customer' => ['id' => $customerId],
        'description' => $invoiceId ? $description : 'Save payment method',
        'merchant_order_data' => ['reference' => $invoiceId ? $reference : 'Card-' . (int) $params['clientdetails']['id']],
        'metadata' => ['whmcs_invoice_id' => (string) $invoiceId, 'whmcs_client_id' => (string) (int) $params['clientdetails']['id']],
    ], 'whmcs-order-' . ($invoiceId ?: ('card-' . (int) $params['clientdetails']['id'])) . '-' . bin2hex(random_bytes(8)));
}

function revolut_remoteinput($params)
{
    try {
        $client = new RevolutClient($params);
        $customerId = revolut_get_or_create_customer($client, $params);
        $order = revolut_create_order($client, $params, $customerId);
        $token = bin2hex(random_bytes(32));
        $customer = [
            'name' => trim(($params['clientdetails']['firstname'] ?? '') . ' ' . ($params['clientdetails']['lastname'] ?? '')),
            'email' => $params['clientdetails']['email'] ?? '', 'phone' => $params['clientdetails']['phonenumber'] ?? '',
            'billingAddress' => ['countryCode' => strtoupper($params['clientdetails']['country'] ?? ''), 'region' => $params['clientdetails']['state'] ?? '', 'city' => $params['clientdetails']['city'] ?? '', 'postcode' => $params['clientdetails']['postcode'] ?? '', 'streetLine1' => $params['clientdetails']['address1'] ?? '', 'streetLine2' => $params['clientdetails']['address2'] ?? ''],
            'orderToken' => $order['token'], 'amount' => $params['amount'] ?? 0, 'currency' => strtoupper($params['currency']),
        ];
        Capsule::table('mod_revolut_sessions')->insert([
            'token' => $token, 'client_id' => (int) $params['clientdetails']['id'],
            'invoice_id' => !empty($params['invoiceid']) ? (int) $params['invoiceid'] : null,
            'pay_method_id' => !empty($params['paymethodid']) ? (int) $params['paymethodid'] : null,
            'order_id' => $order['id'], 'return_url' => $params['returnurl'] ?: ($params['systemurl'] . 'viewinvoice.php?id=' . (int) ($params['invoiceid'] ?? 0)),
            'customer_json' => json_encode($customer), 'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $action = rtrim($params['systemurl'], '/') . '/modules/gateways/revolut/checkout.php';
        return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="session" value="' . $token . '"><noscript><button type="submit">Continue to secure payment</button></noscript></form>';
    } catch (Throwable $e) {
        logTransaction('Revolut', ['stage' => 'remoteinput', 'error' => $e->getMessage()], 'Error');
        return '<div class="alert alert-danger">Unable to initialise the secure payment form. Please refresh the page or choose another payment method.</div>';
    }
}

function revolut_remoteupdate($params)
{
    return revolut_remoteinput($params);
}

function revolut_capture($params)
{
    try {
        revolut_ensure_tables();
        $saved = RevolutToken::decode($params['gatewayid'] ?? '');
        $client = new RevolutClient($params);
        $order = revolut_create_order($client, $params, $saved['customer_id']);
        $payment = $client->post('/api/orders/' . rawurlencode($order['id']) . '/payments', [
            'saved_payment_method' => ['type' => $saved['type'], 'id' => $saved['payment_method_id'], 'initiator' => 'merchant'],
        ], 'whmcs-payment-' . (int) $params['invoiceid']);
        Capsule::table('mod_revolut_transactions')->updateOrInsert(['payment_id' => $payment['id']], ['order_id' => $order['id'], 'invoice_id' => (int) $params['invoiceid'], 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        $state = $payment['state'] ?? '';
        if (in_array($state, ['captured', 'completed'], true)) {
            return ['status' => 'success', 'transid' => $payment['id'], 'fee' => revolut_payment_fee($payment, $params['currency']), 'rawdata' => $payment];
        }
        if (in_array($state, ['declined', 'failed', 'cancelled'], true)) {
            return ['status' => 'declined', 'declinereason' => $payment['decline_reason'] ?? 'Payment declined', 'rawdata' => $payment];
        }
        return ['status' => 'pending', 'transid' => $payment['id'], 'rawdata' => $payment];
    } catch (Throwable $e) {
        return ['status' => 'error', 'rawdata' => ['error' => $e->getMessage()]];
    }
}

function revolut_refund($params)
{
    try {
        revolut_ensure_tables();
        $map = Capsule::table('mod_revolut_transactions')->where('payment_id', $params['transid'])->first();
        $client = new RevolutClient($params);
        $orderId = $map ? (string) $map->order_id : '';

        // Transactions created before mod_revolut_transactions was introduced
        // can still be refunded by resolving their order from Revolut.
        if ($orderId === '') {
            $payment = $client->get('/api/payments/' . rawurlencode($params['transid']));
            $orderId = (string) ($payment['order_id'] ?? '');
        }
        if ($orderId === '') throw new RuntimeException('Could not resolve the Revolut order for this transaction.');

        $minorAmount = revolut_minor_units($params['amount'], $params['currency']);
        // Revolut limits idempotency keys to 50 characters. Payment IDs are
        // UUIDs, so using them verbatim with a prefix exceeds that limit.
        $refundIdempotencyKey = 'whmcs-refund-' . substr(hash('sha256',
            $params['transid'] . '|' . $minorAmount . '|' . strtoupper($params['currency'])
        ), 0, 32);
        $refundReference = revolut_order_reference($params['refundReferenceFormat'] ?? '', $params, 'Refund-{invoice_id}');
        $refundDescription = revolut_order_reference($params['refundDescriptionFormat'] ?? '', $params, 'Refund for invoice #{invoice_id}');
        $refund = $client->post('/api/orders/' . rawurlencode($orderId) . '/refund', [
            'amount' => revolut_minor_units($params['amount'], $params['currency']), 'currency' => strtoupper($params['currency']),
            'description' => $refundDescription,
            'merchant_order_data' => ['reference' => $refundReference],
            'metadata' => ['whmcs_invoice_id' => (string) (int) $params['invoiceid'], 'original_payment_id' => (string) $params['transid']],
        ], $refundIdempotencyKey);

        logTransaction('Revolut', [
            'stage' => 'refund',
            'invoice_id' => (int) $params['invoiceid'],
            'payment_id' => (string) $params['transid'],
            'order_id' => $orderId,
            'refund_order_id' => (string) ($refund['id'] ?? ''),
            'amount' => (string) $params['amount'],
            'currency' => strtoupper($params['currency']),
            'state' => (string) ($refund['state'] ?? ''),
        ], 'Refund initiated');

        return ['status' => 'success', 'transid' => $refund['id'], 'rawdata' => $refund];
    } catch (Throwable $e) {
        $errorData = [
            'stage' => 'refund',
            'invoice_id' => (int) ($params['invoiceid'] ?? 0),
            'payment_id' => (string) ($params['transid'] ?? ''),
            'amount' => (string) ($params['amount'] ?? ''),
            'currency' => strtoupper((string) ($params['currency'] ?? '')),
            'error' => $e->getMessage(),
        ];
        if ($e instanceof RevolutApiException) {
            $errorData['http_status'] = $e->getCode();
            $errorData['response'] = $e->response;

            // Revolut returns 409 while a previously accepted refund using
            // the same idempotency key is still processing. WHMCS records a
            // refund when it is initiated, so this is an accepted retry rather
            // than a failed refund and must not prompt another refund attempt.
            $responseMessage = strtolower((string) ($e->response['message'] ?? ''));
            if ($e->getCode() === 409 && strpos($responseMessage, 'unfinished refund') !== false) {
                $pendingReference = 'pending-' . substr(hash('sha256',
                    ($params['transid'] ?? '') . '|' . ($params['amount'] ?? '') . '|' . ($params['currency'] ?? '')
                ), 0, 24);
                logTransaction('Revolut', $errorData + [
                    'refund_reference' => $pendingReference,
                ], 'Refund already processing');
                return [
                    'status' => 'success',
                    'transid' => $pendingReference,
                    'rawdata' => $errorData,
                ];
            }
        }
        logTransaction('Revolut', $errorData, 'Refund failed');
        return ['status' => 'error', 'rawdata' => $errorData];
    }
}

function revolut_TransactionInformation(array $params = []): Information
{
    revolut_ensure_tables();
    $paymentId = (string) ($params['transactionId'] ?? '');
    $map = Capsule::table('mod_revolut_transactions')->where('payment_id', $paymentId)->first();
    if (!$map) throw new RuntimeException('No Revolut order mapping exists for this transaction.');
    $order = (new RevolutClient($params))->get('/api/orders/' . rawurlencode($map->order_id));
    $payment = null;
    foreach (($order['payments'] ?? []) as $candidate) if (($candidate['id'] ?? '') === $paymentId) $payment = $candidate;
    if (!$payment) throw new RuntimeException('The transaction was not returned by Revolut.');
    $currencyCode = strtoupper($payment['currency'] ?? $order['currency'] ?? '');
    $currency = Currency::where('code', $currencyCode)->first();
    $info = (new Information())->setTransactionId($paymentId)->setAmount(revolut_major_units($payment['amount'] ?? 0, $currencyCode), $currency)->setType('charge')->setStatus($payment['state'] ?? 'unknown')->setDescription($order['description'] ?? ('Revolut order ' . $map->order_id));
    $fee = revolut_payment_fee($payment, $currencyCode);
    if ($fee) $info->setFee($fee, $currency);
    if (!empty($payment['created_at'])) $info->setCreated(Carbon::parse($payment['created_at']));
    return $info->setAdditionalDatum('revolutOrderId', $map->order_id)->setAdditionalDatum('paymentMethod', $payment['payment_method']['type'] ?? 'unknown')->setAdditionalDatum('cardLastFour', $payment['payment_method']['card_last_four'] ?? '');
}
