<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');
require_once __DIR__ . '/lib/Helpers.php';
revolut_require_library();

$gateway = getGatewayVariables('revolut');
if (empty($gateway['type'])) { http_response_code(503); exit('Module not active'); }
$raw = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? '';
$signatures = explode(',', $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '');
if (!$timestamp || !$signatures || empty($gateway['webhookSecret'])) { http_response_code(401); exit('Missing signature'); }
$timestampSeconds = ((int) $timestamp) > 9999999999 ? ((int) $timestamp / 1000) : (int) $timestamp;
if (abs(time() - $timestampSeconds) > 300) { http_response_code(401); exit('Expired signature'); }
$expected = 'v1=' . hash_hmac('sha256', 'v1.' . $timestamp . '.' . $raw, $gateway['webhookSecret']);
$valid = false; foreach ($signatures as $signature) { if (hash_equals($expected, trim($signature))) { $valid = true; break; } }
if (!$valid) { http_response_code(401); exit('Invalid signature'); }
$event = json_decode($raw, true);
if (!is_array($event) || (empty($event['order_id']) && empty($event['dispute_id']))) { http_response_code(400); exit('Invalid payload'); }

try {
    revolut_ensure_tables();
    $client = new RevolutClient($gateway);
    if (!empty($event['dispute_id'])) {
        revolut_handle_dispute($client, (string) $event['dispute_id'], (string) ($event['event'] ?? ''));
        http_response_code(204);
        exit;
    }
    $order = $client->get('/api/orders/' . rawurlencode($event['order_id']));
    // Refunds are separate orders of type "refund". They must never be booked
    // as invoice payments; refunds made outside WHMCS are recorded as refunds.
    $type = strtolower((string) ($order['type'] ?? 'payment'));
    if ($type === 'refund') {
        $result = revolut_record_refund_order($client, $order);
        if (!empty($gateway['debug']) || $result === 'recorded' || strpos($result, 'failed') === 0) logTransaction('Revolut', ['event' => $event['event'] ?? '', 'order_id' => $order['id'], 'related_order_id' => $order['related_order_id'] ?? '', 'result' => $result], 'Refund webhook');
        http_response_code(204);
        exit;
    }
    if ($type !== 'payment') {
        http_response_code(204);
        exit;
    }
    $invoiceId = (int) ($order['metadata']['whmcs_invoice_id'] ?? 0);
    $payment = revolut_find_captured_payment($order);
    $session = WHMCS\Database\Capsule::table('mod_revolut_sessions')->where('order_id', $order['id'])->first();
    if ($session) {
        // Checkout session (new card, Revolut Pay or wallet): save the method too.
        if ($session->invoice_id) checkCbInvoiceID((int) $session->invoice_id, 'revolut');
        revolut_finalise_session($session, $order);
    } elseif ($invoiceId && $payment) {
        checkCbInvoiceID($invoiceId, 'revolut');
        WHMCS\Database\Capsule::table('mod_revolut_transactions')->updateOrInsert(['payment_id' => $payment['id']], ['order_id' => $order['id'], 'invoice_id' => $invoiceId, 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        if (!WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $payment['id'])->exists()) {
            addInvoicePayment($invoiceId, $payment['id'], revolut_major_units($payment['amount'] ?? $order['amount'], $payment['currency'] ?? $order['currency']), revolut_payment_fee($payment, $payment['currency'] ?? $order['currency']), 'revolut');
        }
    }
    if (!empty($gateway['debug'])) logTransaction('Revolut', ['event' => $event['event'] ?? '', 'order_id' => $event['order_id'], 'invoice_id' => $invoiceId], 'Webhook accepted');
    http_response_code(204);
} catch (Throwable $e) {
    logTransaction('Revolut', ['stage' => 'webhook', 'order_id' => $event['order_id'] ?? '', 'dispute_id' => $event['dispute_id'] ?? '', 'error' => $e->getMessage()], 'Error');
    http_response_code(500); echo 'Temporary processing error';
}
