<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gateway = getGatewayVariables('revolut');
if (empty($gateway['type'])) {
    http_response_code(404);
    exit;
}

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['session'] ?? ''));
$session = $token
    ? WHMCS\Database\Capsule::table('mod_revolut_sessions')->where('token', $token)->first()
    : null;
if (!$session || strtotime($session->expires_at) < time()) {
    http_response_code(404);
    exit;
}

$allowedStages = ['payments_initialise', 'payments_unavailable'];
$stage = (string) ($_POST['stage'] ?? '');
if (!in_array($stage, $allowedStages, true)) $stage = 'payments_initialise';
$message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($_POST['message'] ?? 'Unknown SDK error'));

logTransaction('Revolut', [
    'stage' => $stage,
    'invoice_id' => (int) $session->invoice_id,
    'environment' => (string) ($gateway['environment'] ?? ''),
    'error' => substr($message, 0, 500),
], 'Checkout SDK error');

http_response_code(204);
