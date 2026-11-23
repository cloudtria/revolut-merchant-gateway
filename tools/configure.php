<?php
/**
 * Configure the Revolut gateway on a WHMCS install from the command line, so
 * keys never pass through a browser, chat or shell history.
 *
 *   sudo cat /etc/cloudtria/revolut.env \
 *     | sudo -u <site user> php tools/configure.php /path/to/whmcs sandbox|production
 *
 * The env file (root, mode 600) holds KEY=value lines:
 *   REVOLUT_SANDBOX_SECRET, REVOLUT_SANDBOX_PUBLIC,
 *   REVOLUT_PRODUCTION_SECRET, REVOLUT_PRODUCTION_PUBLIC
 *
 * Activates the gateway if needed, saves the keys and environment, registers
 * the webhook for this install's System URL (or reuses an existing one), and
 * stores its signing secret. Prints no secrets.
 */

if (PHP_SAPI !== 'cli' || $argc < 3 || !in_array($argv[2], ['sandbox', 'production'], true)) {
    fwrite(STDERR, "usage: php configure.php <whmcs dir> sandbox|production < env\n");
    exit(2);
}
$env = $argv[2];
$vars = [];
foreach (preg_split('/\R/', stream_get_contents(STDIN)) as $line) {
    if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
        $vars[$m[1]] = trim($m[2], "\"'");
    }
}
$prefix = 'REVOLUT_' . strtoupper($env) . '_';
$secret = $vars[$prefix . 'SECRET'] ?? '';
$public = $vars[$prefix . 'PUBLIC'] ?? '';
if ($secret === '') {
    fwrite(STDERR, "{$prefix}SECRET is missing from the env input\n");
    exit(1);
}

chdir($argv[1]);
require $argv[1] . '/init.php';
require_once ROOTDIR . '/modules/gateways/revolut/lib/Helpers.php';
revolut_require_library();

use WHMCS\Module\GatewaySetting;

$gateway = new WHMCS\Module\Gateway();
$gateway->load('revolut');
if (!$gateway->isActiveGateway('revolut')) {
    $gateway->activate();
    echo "activated gateway\n";
}

$client = new RevolutClient(['environment' => $env, 'secretKey' => $secret]);
$url = revolut_webhook_url();
$events = ['ORDER_COMPLETED', 'ORDER_FAILED', 'ORDER_CANCELLED', 'ORDER_PAYMENT_DECLINED', 'ORDER_PAYMENT_FAILED'];
$hook = null;
foreach (revolut_list_webhooks($client) as $h) {
    if (rtrim((string) ($h['url'] ?? ''), '/') === $url) $hook = $h;
}
if ($hook) {
    $hook = $client->get('/api/webhooks/' . rawurlencode($hook['id']));
    echo "webhook exists: {$hook['id']}\n";
} else {
    $hook = $client->post('/api/webhooks', ['url' => $url, 'events' => $events]);
    echo "webhook registered: {$hook['id']}\n";
}
if (empty($hook['signing_secret'])) {
    fwrite(STDERR, "Revolut did not return a signing secret for the webhook\n");
    exit(1);
}

$settings = [
    'environment' => $env,
    'secretKey' => $secret,
    'webhookSecret' => $hook['signing_secret'],
];
if ($public !== '') $settings['publicKey'] = $public;
foreach ($settings as $k => $v) {
    GatewaySetting::setValue('revolut', $k, $v);
}
foreach (['apiVersion' => '2026-08-17'] as $k => $v) {
    if ((string) GatewaySetting::getValue('revolut', $k) === '') GatewaySetting::setValue('revolut', $k, $v);
}
echo "saved: environment={$env}, secret key, ", $public !== '' ? 'public key, ' : '(no public key), ', "webhook secret\n";
echo "webhook url: {$url}\nevents: ", implode(',', $hook['events'] ?? $events), "\n";
