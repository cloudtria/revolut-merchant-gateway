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
    $done = $session->invoice_id ? '' : 'Payment method saved. Returning to your account…';
    if (!empty($session->completed_at)) revolut_redirect_page($returnUrl, true, $done);
    $client = new RevolutClient($gateway);
    $order = revolut_wait_for_order($client, $session->order_id);
    $result = revolut_finalise_session($session, $order);
    if ($result === 'pending') {
        $state = $order['state'] ?? 'pending';
        if (in_array($state, ['failed', 'cancelled'], true)) revolut_redirect_page($returnUrl, false, 'The payment was not completed. Please try again.');
        logTransaction('Revolut', ['stage' => 'complete', 'order_id' => $session->order_id, 'state' => $state], 'Payment not yet settled');
        revolut_redirect_page($returnUrl, false, 'Your payment is still being confirmed. The invoice will update automatically.');
    }
    revolut_redirect_page($returnUrl, true, $done);
} catch (Throwable $e) {
    logTransaction('Revolut', ['stage' => 'complete', 'order_id' => $session->order_id, 'error' => $e->getMessage()], 'Error');
    revolut_redirect_page($returnUrl, false, revolut_safe_error($e));
}
