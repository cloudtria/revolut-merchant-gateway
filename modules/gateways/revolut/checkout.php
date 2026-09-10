<?php
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
require_once __DIR__ . '/lib/RevolutClient.php';
require_once __DIR__ . '/lib/Helpers.php';

use Cloudtria\WHMCS\Revolut\Helpers;
use Cloudtria\WHMCS\Revolut\RevolutClient;

$gateway = getGatewayVariables('revolut');
if (empty($gateway['type'])) { http_response_code(503); exit('Revolut gateway is not active.'); }

try {
    $ctx = Helpers::verifyContext($_POST['context'] ?? '', $gateway['secretKey']);
    if (empty($ctx['client_id']) || empty($ctx['email'])) throw new RuntimeException('Invalid customer context.');

    $client = new RevolutClient($gateway);
    $customerId = Helpers::getOrCreateCustomer($client, $ctx['client_id'], [
        'firstname' => $ctx['firstname'], 'lastname' => $ctx['lastname'], 'email' => $ctx['email'], 'phonenumber' => $ctx['phone'],
    ]);
    $isPayment = $ctx['action'] === 'payment';
    $minor = $isPayment ? Helpers::minorUnits($ctx['amount'], $ctx['currency']) : 0;
    $reference = $isPayment ? ('whmcs-invoice-' . (int) $ctx['invoice_id']) : ('whmcs-paymethod-client-' . (int) $ctx['client_id'] . '-' . bin2hex(random_bytes(4)));
    $orderPayload = [
        'amount' => $minor,
        'currency' => $ctx['currency'] ?: 'NZD',
        'customer' => ['id' => $customerId],
        'capture_mode' => 'automatic',
        'description' => $isPayment ? ('WHMCS invoice #' . (int) $ctx['invoice_id']) : 'Save payment method for WHMCS',
        'merchant_order_data' => ['reference' => $reference],
        'metadata' => [
            'whmcs_client_id' => (string) ((int) $ctx['client_id']),
            'whmcs_invoice_id' => (string) ((int) $ctx['invoice_id']),
            'whmcs_action' => $ctx['action'],
        ],
    ];
    $order = $client->createOrder($orderPayload, 'whmcs-ui-' . sha1($_POST['context'] . '|' . $reference));
    if (empty($order['id']) || empty($order['token'])) throw new RuntimeException('Revolut did not return an order ID/token.');

    $ctx['revolut_customer_id'] = $customerId;
    $ctx['revolut_order_id'] = $order['id'];
    $ctx['reference'] = $reference;
    $ctx['ts'] = time();
    $finishContext = Helpers::signContext($ctx, $gateway['secretKey']);
    $sdkUrl = $gateway['sdkUrl'] ?: 'https://merchant.revolut.com/embed.js';
    $mode = ($gateway['environment'] ?? 'sandbox') === 'production' ? 'prod' : 'sandbox';
    $callback = rtrim($gateway['systemurl'], '/') . '/modules/gateways/callback/revolut.php';
    $name = trim($ctx['firstname'] . ' ' . $ctx['lastname']);
    $billing = array_filter([
        'countryCode' => $ctx['country'], 'region' => $ctx['state'], 'city' => $ctx['city'], 'postcode' => $ctx['postcode'],
        'streetLine1' => $ctx['address1'], 'streetLine2' => $ctx['address2'],
    ], function ($v) { return $v !== ''; });
} catch (Throwable $e) {
    http_response_code(400);
    exit('<div style="font-family:sans-serif;color:#b91c1c;padding:20px">Unable to initialise Revolut checkout: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>');
}
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;padding:18px;color:#111827}.box{max-width:620px;margin:auto}.field{min-height:52px;margin:12px 0}.btn{width:100%;padding:12px 16px;border:0;border-radius:8px;background:#111827;color:#fff;font-weight:600;cursor:pointer}.btn:disabled{opacity:.5}.msg{margin-top:10px;font-size:14px;color:#b91c1c}</style>
<script src="<?= htmlspecialchars($sdkUrl, ENT_QUOTES, 'UTF-8') ?>"></script></head>
<body><div class="box"><div id="card-field" class="field"></div><button id="pay" class="btn" disabled><?= $isPayment ? 'Pay securely' : 'Save card' ?></button><div id="msg" class="msg"></div></div>
<form id="complete" method="post" action="<?= htmlspecialchars($callback, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="context" value="<?= htmlspecialchars($finishContext, ENT_QUOTES, 'UTF-8') ?>"></form>
<script>
(async function(){
  const msg=document.getElementById('msg'), btn=document.getElementById('pay');
  try {
    const instance=await RevolutCheckout(<?= json_encode($order['token']) ?>, <?= json_encode($mode) ?>);
    const card=instance.createCardField({
      target:document.getElementById('card-field'),
      onSuccess:function(){document.getElementById('complete').submit();},
      onError:function(err){msg.textContent=(err&&err.message)?err.message:String(err||'Payment failed');btn.disabled=false;},
      onValidation:function(errors){btn.disabled=Array.isArray(errors)&&errors.length>0;}
    });
    btn.disabled=false;
    btn.addEventListener('click',function(){btn.disabled=true;msg.textContent='';card.submit({
      name:<?= json_encode($name) ?>,
      email:<?= json_encode($ctx['email']) ?>,
      phone:<?= json_encode($ctx['phone']) ?>,
      savePaymentMethodFor:'merchant',
      billingAddress:<?= json_encode($billing, JSON_UNESCAPED_SLASHES) ?>
    });});
  } catch(e) { msg.textContent=e&&e.message?e.message:String(e); }
})();
</script></body></html>
