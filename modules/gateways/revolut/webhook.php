<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');
require_once __DIR__ . '/lib/RevolutClient.php';
require_once __DIR__ . '/lib/Helpers.php';

use Cloudtria\WHMCS\Revolut\Helpers;
use Cloudtria\WHMCS\Revolut\RevolutClient;

$gateway = getGatewayVariables('revolut');
if (empty($gateway['type'])) { http_response_code(404); exit; }
$secret = (string) ($gateway['webhookSecret'] ?? '');
if ($secret === '') { http_response_code(503); exit('Webhook secret not configured'); }

$raw = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_REVOLUT_REQUEST_TIMESTAMP'] ?? '';
$header = $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '';
if (!$timestamp || !$header || !ctype_digit((string) $timestamp)) { http_response_code(401); exit('Missing signature'); }
$tsSeconds = ((int) $timestamp > 20000000000) ? ((int) $timestamp / 1000) : (int) $timestamp;
if (abs(time() - $tsSeconds) > 300) { http_response_code(401); exit('Stale webhook'); }
$payloadToSign = 'v1.' . $timestamp . '.' . $raw;
$expected = 'v1=' . hash_hmac('sha256', $payloadToSign, $secret);
$valid = false;
foreach (array_map('trim', explode(',', $header)) as $candidate) if (hash_equals($expected, $candidate)) $valid = true;
if (!$valid) { http_response_code(401); exit('Invalid signature'); }

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['event']) || empty($event['order_id'])) { http_response_code(400); exit('Invalid payload'); }

try {
    $type = $event['event'];
    if ($type === 'ORDER_COMPLETED') {
        $client = new RevolutClient($gateway);
        $order = $client->getOrder($event['order_id']);
        $reference = (string) ($order['merchant_order_data']['reference'] ?? '');
        if (preg_match('/^whmcs-invoice-(\d+)$/', $reference, $m)) {
            $invoiceId = (int) $m[1];
            $payment = Helpers::findSuccessfulPayment($order);
            if ($payment && !empty($payment['id'])) {
                try {
                    checkCbInvoiceID($invoiceId, 'revolut');
                    checkCbTransID($payment['id']);
                    addInvoicePayment($invoiceId, $payment['id'], Helpers::majorUnits($payment['amount'] ?? $order['amount'], $order['currency']), 0, 'revolut');
                    logTransaction('revolut', Helpers::sanitize($event), 'Webhook Reconciled');
                } catch (Throwable $duplicateOrInvalid) {
                    // Usually duplicate transaction because browser callback already applied it.
                }
            }
        }
    } elseif (in_array($type, ['ORDER_FAILED','ORDER_PAYMENT_DECLINED','ORDER_PAYMENT_FAILED','ORDER_CANCELLED'], true)) {
        logTransaction('revolut', Helpers::sanitize($event), 'Webhook ' . $type);
    }
    http_response_code(200); echo 'ok';
} catch (Throwable $e) {
    logTransaction('revolut', ['message' => $e->getMessage(), 'event' => Helpers::sanitize($event)], 'Webhook Error');
    http_response_code(500); echo 'error';
}
