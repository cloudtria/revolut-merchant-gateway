<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');
require_once __DIR__ . '/../revolut/lib/RevolutClient.php';
require_once __DIR__ . '/../revolut/lib/Helpers.php';

use Cloudtria\WHMCS\Revolut\Helpers;
use Cloudtria\WHMCS\Revolut\RevolutClient;

$gateway = getGatewayVariables('revolut');
if (empty($gateway['type'])) die('Module Not Activated');

try {
    $ctx = Helpers::verifyContext($_POST['context'] ?? '', $gateway['secretKey']);
    $client = new RevolutClient($gateway);
    $order = $client->getOrder($ctx['revolut_order_id']);
    if (($order['merchant_order_data']['reference'] ?? '') !== ($ctx['reference'] ?? '')) throw new RuntimeException('Revolut order reference mismatch.');
    if ((string) ($order['state'] ?? '') !== 'completed') throw new RuntimeException('Revolut order is not completed. Current state: ' . ($order['state'] ?? 'unknown'));

    $payment = Helpers::findSuccessfulPayment($order);
    $paymentMethodId = $payment['payment_method']['id'] ?? null;
    $method = Helpers::findPaymentMethod($client, $ctx['revolut_customer_id'], $paymentMethodId);
    if (!$method || empty($method['id'])) throw new RuntimeException('Saved Revolut card could not be resolved.');
    if (($method['saved_for'] ?? '') !== 'merchant') throw new RuntimeException('Card was not saved for merchant-initiated payments.');

    $remoteToken = Helpers::tokenEncode($ctx['revolut_customer_id'], $method['id']);
    $last4 = (string) ($method['last_four'] ?? ($payment['payment_method']['last_four'] ?? '0000'));
    $cardType = Helpers::cardType($method['brand'] ?? ($payment['payment_method']['brand'] ?? 'card'));
    $expiry = Helpers::expiryMmyy($method);

    if ($ctx['action'] === 'payment') {
        $invoiceId = checkCbInvoiceID((int) $ctx['invoice_id'], 'revolut');
        if (!$payment || empty($payment['id'])) throw new RuntimeException('No successful Revolut payment found on completed order.');
        // Save/update the remote card before duplicate-transaction checking. This is
        // intentional: a fast ORDER_COMPLETED webhook may have already applied the
        // invoice payment, but the browser callback still needs to persist the token.
        invoiceSaveRemoteCard($invoiceId, $last4, $cardType, $expiry, $remoteToken);
        checkCbTransID($payment['id']);
        addInvoicePayment($invoiceId, $payment['id'], (float) $ctx['amount'], 0, 'revolut');
        logTransaction('revolut', Helpers::sanitize(['order' => $order, 'payment_method' => $method]), 'Success');
        callback3DSecureRedirect($invoiceId, true);
        exit;
    }

    createCardPayMethod((int) $ctx['client_id'], 'revolut', $last4, $expiry, $cardType, null, null, $remoteToken);
    logTransaction('revolut', Helpers::sanitize(['order' => $order, 'payment_method' => $method]), 'Pay Method Created');
    echo '<div style="font-family:sans-serif;padding:20px;color:#166534">Payment method saved successfully. You can close this window.</div>';
} catch (Throwable $e) {
    logTransaction('revolut', ['message' => $e->getMessage()], 'Callback Error');
    if (!empty($ctx['invoice_id'])) {
        callback3DSecureRedirect((int) $ctx['invoice_id'], false);
        exit;
    }
    http_response_code(400);
    echo '<div style="font-family:sans-serif;padding:20px;color:#b91c1c">Unable to save payment method: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}
