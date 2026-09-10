<?php
use Cloudtria\WHMCS\Revolut\Helpers;
use Cloudtria\WHMCS\Revolut\RevolutClient;
use Cloudtria\WHMCS\Revolut\RevolutException;

if (!defined('WHMCS')) die('This file cannot be accessed directly');
require_once __DIR__ . '/revolut/lib/RevolutClient.php';
require_once __DIR__ . '/revolut/lib/Helpers.php';

function revolut_MetaData()
{
    return ['DisplayName' => 'Revolut Merchant', 'APIVersion' => '1.1'];
}

function revolut_config()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Revolut'],
        'secretKey' => ['FriendlyName' => 'Secret Key', 'Type' => 'password', 'Size' => '80', 'Description' => 'Merchant API secret key'],
        'publicKey' => ['FriendlyName' => 'Public Key', 'Type' => 'password', 'Size' => '80', 'Description' => 'Merchant public key (reserved for future Revolut Pay/UI extensions)'],
        'environment' => ['FriendlyName' => 'Environment', 'Type' => 'dropdown', 'Options' => ['sandbox' => 'Sandbox', 'production' => 'Production'], 'Default' => 'sandbox'],
        'apiVersion' => ['FriendlyName' => 'API Version', 'Type' => 'text', 'Default' => '2026-08-17'],
        'webhookSecret' => ['FriendlyName' => 'Webhook Signing Secret', 'Type' => 'password', 'Size' => '80'],
        'sdkUrl' => ['FriendlyName' => 'Checkout SDK URL', 'Type' => 'text', 'Default' => 'https://merchant.revolut.com/embed.js', 'Description' => 'Revolut Checkout embed script URL'],
        'debug' => ['FriendlyName' => 'Debug Logging', 'Type' => 'yesno', 'Description' => 'Log sanitised API responses in Gateway Log'],
    ];
}

function revolut_nolocalcc() {}

function revolut_remoteinput($params)
{
    $payload = [
        'ts' => time(),
        'action' => ((float) ($params['amount'] ?? 0) > 0) ? 'payment' : 'create',
        'client_id' => (int) ($params['clientdetails']['id'] ?? 0),
        'invoice_id' => (int) ($params['invoiceid'] ?? 0),
        'amount' => (string) ($params['amount'] ?? '0'),
        'currency' => strtoupper((string) ($params['currency'] ?? '')),
        'firstname' => (string) ($params['clientdetails']['firstname'] ?? ''),
        'lastname' => (string) ($params['clientdetails']['lastname'] ?? ''),
        'email' => (string) ($params['clientdetails']['email'] ?? ''),
        'phone' => (string) ($params['clientdetails']['phonenumber'] ?? ''),
        'address1' => (string) ($params['clientdetails']['address1'] ?? ''),
        'address2' => (string) ($params['clientdetails']['address2'] ?? ''),
        'city' => (string) ($params['clientdetails']['city'] ?? ''),
        'state' => (string) ($params['clientdetails']['state'] ?? ''),
        'postcode' => (string) ($params['clientdetails']['postcode'] ?? ''),
        'country' => strtoupper((string) ($params['clientdetails']['country'] ?? '')),
    ];
    $context = Helpers::signContext($payload, $params['secretKey']);
    $action = rtrim($params['systemurl'], '/') . '/modules/gateways/revolut/checkout.php';
    return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">' .
        '<input type="hidden" name="context" value="' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '">' .
        '<noscript><input type="submit" value="Continue to payment"></noscript></form>';
}

function revolut_remoteupdate($params)
{
    return '<div class="alert alert-info">Revolut saved cards are replaced rather than edited. Please add a new payment method, set it as default, then remove the old one.</div>';
}

function revolut_capture($params)
{
    try {
        if (empty($params['gatewayid'])) throw new \RuntimeException('No saved Revolut payment method is available.');
        $token = Helpers::tokenDecode($params['gatewayid']);
        $client = new RevolutClient($params);
        $minor = Helpers::minorUnits($params['amount'], $params['currency']);
        $reference = 'whmcs-invoice-' . (int) $params['invoiceid'];
        $order = $client->createOrder([
            'amount' => $minor,
            'currency' => strtoupper($params['currency']),
            'customer' => ['id' => $token['customer_id']],
            'capture_mode' => 'automatic',
            'description' => 'WHMCS invoice #' . (int) $params['invoiceid'],
            'merchant_order_data' => ['reference' => $reference, 'url' => rtrim($params['systemurl'], '/') . '/viewinvoice.php?id=' . (int) $params['invoiceid']],
            'metadata' => ['whmcs_invoice_id' => (string) ((int) $params['invoiceid'])],
        ], 'whmcs-order-invoice-' . (int) $params['invoiceid'] . '-' . sha1($params['gatewayid'] . '|' . $minor));

        $payment = $client->payOrder($order['id'], [
            'saved_payment_method' => [
                'type' => 'card',
                'id' => $token['payment_method_id'],
                'initiator' => 'merchant',
            ],
        ], 'whmcs-pay-invoice-' . (int) $params['invoiceid'] . '-' . sha1($params['gatewayid'] . '|' . $minor));

        $state = (string) ($payment['state'] ?? '');
        if (in_array($state, ['captured','completed'], true)) {
            return ['status' => 'success', 'transid' => $payment['id'], 'rawdata' => Helpers::sanitize($payment)];
        }
        if (in_array($state, ['pending','authorisation_started','authorisation_passed','authorised','capture_started','completing'], true)) {
            return ['status' => 'pending', 'transid' => $payment['id'] ?? $order['id'], 'rawdata' => Helpers::sanitize($payment)];
        }
        return ['status' => 'declined', 'declinereason' => $payment['decline_reason'] ?? ('Revolut payment state: ' . $state), 'rawdata' => Helpers::sanitize($payment)];
    } catch (RevolutException $e) {
        return ['status' => 'declined', 'declinereason' => $e->getMessage(), 'rawdata' => Helpers::sanitize($e->getResponse())];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'rawdata' => ['message' => $e->getMessage()]];
    }
}

function revolut_refund($params)
{
    try {
        $client = new RevolutClient($params);
        $payment = $client->getPayment($params['transid']);
        if (empty($payment['order_id'])) throw new \RuntimeException('Could not resolve the Revolut order for this transaction.');
        $currency = strtoupper($params['currency']);
        $minor = Helpers::minorUnits($params['amount'], $currency);
        $refund = $client->refundOrder($payment['order_id'], [
            'amount' => $minor,
            'currency' => $currency,
            'description' => 'WHMCS refund for transaction ' . $params['transid'],
            'merchant_order_data' => ['reference' => 'whmcs-refund-' . sha1($params['transid'] . '|' . $minor)],
        ], 'whmcs-refund-' . sha1($params['transid'] . '|' . $minor));
        return ['status' => 'success', 'transid' => $refund['id'] ?? '', 'rawdata' => Helpers::sanitize($refund)];
    } catch (RevolutException $e) {
        return ['status' => 'error', 'rawdata' => Helpers::sanitize($e->getResponse()) + ['message' => $e->getMessage()]];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'rawdata' => ['message' => $e->getMessage()]];
    }
}
