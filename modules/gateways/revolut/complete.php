<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');
require_once __DIR__ . '/lib/Helpers.php';
revolut_require_library();

$gateway = getGatewayVariables('revolut');
$fallback = rtrim($gateway['systemurl'] ?? '/', '/') . '/clientarea.php';
if (empty($gateway['type'])) revolut_redirect_page($fallback, false, 'This payment method is unavailable.');
revolut_ensure_tables();
$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['session'] ?? ''));
$session = $token ? WHMCS\Database\Capsule::table('mod_revolut_sessions')->where('token', $token)->first() : null;
if (!$session) revolut_redirect_page($fallback, false, 'This payment session is no longer available.');
$returnUrl = $session->return_url ?: $fallback;

try {
    $client = new RevolutClient($gateway);
    $order = $client->get('/api/orders/' . rawurlencode($session->order_id));
    $payment = revolut_find_captured_payment($order);
    if (!$payment) {
        $state = $order['state'] ?? 'pending';
        if (in_array($state, ['failed','cancelled'], true)) revolut_redirect_page($returnUrl, false, 'The payment was not completed. Please try again.');
        revolut_redirect_page($returnUrl, false, 'Your payment is still being confirmed. The invoice will update automatically.');
    }
    $method = $payment['payment_method'] ?? [];
    $methodType = strtolower((string) ($method['type'] ?? ''));
    if (in_array($methodType, ['revolut_pay_card', 'revolut_pay_account'], true)) $methodType = 'revolut_pay';
    $paymentMethodId = $method['id'] ?? '';
    if ($paymentMethodId && in_array($methodType, ['card', 'revolut_pay'], true)) {
        $customerId = $order['customer']['id'] ?? revolut_customer_id_for_client($session->client_id);
        $remoteToken = RevolutToken::encode($customerId, $paymentMethodId, $methodType);
        $lastFour = $method['card_last_four'] ?? $method['last_four'] ?? '0000';
        $expiry = revolut_whmcs_expiry($method, $methodType);
        $brand = $methodType === 'revolut_pay'
            ? 'Revolut Pay'
            : ucwords(str_replace('_', ' ', $method['card_brand'] ?? $method['brand'] ?? 'Card'));
        if ($session->pay_method_id) updateCardPayMethod($session->client_id, $session->pay_method_id, $expiry, null, null, $remoteToken);
        else createCardPayMethod($session->client_id, 'revolut', $lastFour, $expiry, $brand, null, null, $remoteToken, 'billing', $methodType === 'revolut_pay' ? 'Revolut Pay' : 'Revolut - ' . $brand . '-' . $lastFour);
    }
    WHMCS\Database\Capsule::table('mod_revolut_transactions')->updateOrInsert(['payment_id' => $payment['id']], ['order_id' => $session->order_id, 'invoice_id' => $session->invoice_id, 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
    if ($session->invoice_id) {
        $exists = WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $payment['id'])->exists();
        if (!$exists) addInvoicePayment($session->invoice_id, $payment['id'], revolut_major_units($payment['amount'] ?? $order['amount'], $payment['currency'] ?? $order['currency']), revolut_payment_fee($payment, $payment['currency'] ?? $order['currency']), 'revolut');
    }
    WHMCS\Database\Capsule::table('mod_revolut_sessions')->where('token', $token)->delete();
    revolut_redirect_page($returnUrl, true, $session->invoice_id ? '' : 'Payment method saved. Returning to your account…');
} catch (Throwable $e) {
    logTransaction('Revolut', ['stage' => 'complete', 'order_id' => $session->order_id, 'error' => $e->getMessage()], 'Error');
    revolut_redirect_page($returnUrl, false, revolut_safe_error($e));
}
