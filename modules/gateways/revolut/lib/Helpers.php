<?php

use WHMCS\Database\Capsule;

function revolut_require_library()
{
    require_once __DIR__ . '/RevolutClient.php';
    require_once __DIR__ . '/Token.php';
}

function revolut_ensure_tables()
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_revolut_customers')) {
        $schema->create('mod_revolut_customers', function ($table) {
            $table->integer('client_id')->unsigned()->primary();
            $table->string('customer_id', 64)->unique();
            $table->timestamps();
        });
    }
    if (!$schema->hasTable('mod_revolut_sessions')) {
        $schema->create('mod_revolut_sessions', function ($table) {
            $table->string('token', 64)->primary();
            $table->integer('client_id')->unsigned();
            $table->integer('invoice_id')->unsigned()->nullable();
            $table->integer('pay_method_id')->unsigned()->nullable();
            $table->string('order_id', 64);
            $table->string('return_url', 1024);
            $table->text('customer_json');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    } elseif (!$schema->hasColumn('mod_revolut_sessions', 'completed_at')) {
        $schema->table('mod_revolut_sessions', function ($table) {
            $table->timestamp('completed_at')->nullable();
        });
    }
    if (!$schema->hasTable('mod_revolut_transactions')) {
        $schema->create('mod_revolut_transactions', function ($table) {
            $table->string('payment_id', 64)->primary();
            $table->string('order_id', 64)->index();
            $table->integer('invoice_id')->unsigned()->nullable()->index();
            $table->timestamps();
        });
    }
}

/**
 * Return the customer mapping column names used by this installation.
 * Older releases created whmcs_client_id/revolut_customer_id, while
 * fresh installations use client_id/customer_id. Supporting both avoids a
 * destructive migration and preserves existing saved-card customer mappings.
 */
function revolut_customer_columns()
{
    $schema = Capsule::schema();
    if ($schema->hasColumn('mod_revolut_customers', 'whmcs_client_id')) {
        return ['client' => 'whmcs_client_id', 'customer' => 'revolut_customer_id'];
    }
    return ['client' => 'client_id', 'customer' => 'customer_id'];
}

function revolut_customer_id_for_client($clientId)
{
    revolut_ensure_tables();
    $columns = revolut_customer_columns();
    return Capsule::table('mod_revolut_customers')
        ->where($columns['client'], (int) $clientId)
        ->value($columns['customer']);
}

function revolut_currency_for_client(array $params)
{
    $currency = strtoupper(trim((string) ($params['currency'] ?? '')));
    if ($currency !== '') return $currency;

    $currencyId = Capsule::table('tblclients')
        ->where('id', (int) ($params['clientdetails']['id'] ?? 0))
        ->value('currency');
    $currency = strtoupper((string) Capsule::table('tblcurrencies')
        ->where('id', (int) $currencyId)
        ->value('code'));
    if ($currency === '') throw new RuntimeException('Could not determine the client currency.');
    return $currency;
}

function revolut_whmcs_expiry(array $method, $methodType)
{
    $expiry = preg_replace('/[^0-9]/', '', (string) ($method['card_expiry'] ?? ''));
    if (strlen($expiry) === 4) return $expiry;

    $month = (int) ($method['expiry_month'] ?? 0);
    $year = (int) ($method['expiry_year'] ?? 0);
    if ($month >= 1 && $month <= 12 && $year >= 2000) {
        return sprintf('%02d%02d', $month, $year % 100);
    }

    // WHMCS models every remote input method as a card and requires an
    // expiry, even though Revolut Pay account methods do not expire.
    if ($methodType === 'revolut_pay') return '12' . date('y', strtotime('+10 years'));
    return '01' . date('y', strtotime('+3 years'));
}

/** Revolut limits idempotency keys to 50 characters. */
function revolut_idempotency_key($prefix, $unique)
{
    $key = $prefix . $unique;
    return strlen($key) <= 50 ? $key : $prefix . substr(hash('sha256', (string) $unique), 0, 50 - strlen($prefix));
}

/** Webhooks registered for this key. Newer API versions wrap the list in "webhooks". */
function revolut_list_webhooks(RevolutClient $client)
{
    $res = $client->get('/api/webhooks');
    $list = isset($res['webhooks']) && is_array($res['webhooks']) ? $res['webhooks'] : $res;
    return array_values(array_filter((array) $list, 'is_array'));
}

/**
 * Revolut serves a separate embed.js per environment and the script host must
 * match the order's environment (a sandbox order is "not found" by the
 * production script). Only a custom URL in the settings overrides this.
 */
function revolut_sdk_url(array $gateway)
{
    $sandbox = ($gateway['environment'] ?? 'sandbox') !== 'production';
    $default = $sandbox ? 'https://sandbox-merchant.revolut.com/embed.js' : 'https://merchant.revolut.com/embed.js';
    $custom = trim((string) ($gateway['checkoutSdkUrl'] ?? ''));
    $stock = ['', 'https://merchant.revolut.com/embed.js', 'https://sandbox-merchant.revolut.com/embed.js'];
    return in_array($custom, $stock, true) ? $default : $custom;
}

function revolut_webhook_url()
{
    return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/gateways/revolut/webhook.php';
}

function revolut_minor_units($amount, $currency)
{
    $zero = ['BIF','CLP','DJF','GNF','ISK','JPY','KMF','KRW','PYG','RWF','UGX','UYI','VND','VUV','XAF','XOF','XPF'];
    $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
    $power = in_array(strtoupper($currency), $zero, true) ? 0 : (in_array(strtoupper($currency), $three, true) ? 3 : 2);
    return (int) round(((float) $amount) * (10 ** $power));
}

function revolut_major_units($amount, $currency)
{
    return revolut_minor_units(1, $currency) ? ((float) $amount / revolut_minor_units(1, $currency)) : (float) $amount;
}

function revolut_order_reference($format, array $params, $fallback)
{
    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $replacements = [
        '{invoice_id}' => (string) $invoiceId,
        '{client_id}' => (string) (int) ($params['clientdetails']['id'] ?? 0),
        '{amount}' => (string) ($params['amount'] ?? ''),
        '{currency}' => strtoupper((string) ($params['currency'] ?? '')),
        '{company_name}' => trim((string) ($params['companyname'] ?? '')),
    ];
    $reference = trim(strtr((string) $format, $replacements));
    if ($reference === '') $reference = strtr($fallback, $replacements);
    return substr($reference, 0, 200);
}

function revolut_payment_fee(array $payment, $invoiceCurrency)
{
    $total = 0;
    foreach (($payment['fees'] ?? []) as $fee) {
        if (strtoupper((string) ($fee['currency'] ?? '')) === strtoupper($invoiceCurrency)) {
            $total += (int) ($fee['amount'] ?? 0);
        }
    }
    return revolut_major_units($total, $invoiceCurrency);
}

function revolut_safe_error($exception)
{
    if ($exception instanceof RevolutApiException) {
        $code = $exception->response['code'] ?? '';
        $map = [
            'insufficient_funds' => 'The card has insufficient funds.',
            'card_declined' => 'The card was declined. Please use another card or contact your bank.',
            'expired_card' => 'The card has expired. Please use another card.',
            'authentication_failed' => 'Card verification failed. Please try again.',
        ];
        if (isset($map[$code])) return $map[$code];
    }
    return 'We could not complete the payment. Please try again or use another payment method.';
}

function revolut_redirect_page($url, $success, $message = '')
{
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message ?: ($success ? 'Payment received. Returning to your invoice…' : 'Returning to your invoice…'), ENT_QUOTES, 'UTF-8');
    $jsonUrl = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta http-equiv="refresh" content="2;url=' . $safeUrl . '"><title>Payment</title></head>';
    echo '<body style="font-family:system-ui,sans-serif;text-align:center;padding:3rem;color:#182230"><p>' . $safeMessage . '</p>';
    echo '<p><a href="' . $safeUrl . '">Continue to invoice</a></p><script>try{window.top.location.replace(' . $jsonUrl . ')}catch(e){window.location.replace(' . $jsonUrl . ')}</script></body></html>';
    exit;
}

function revolut_find_captured_payment(array $order)
{
    foreach (array_reverse($order['payments'] ?? []) as $payment) {
        if (in_array($payment['state'] ?? '', ['captured', 'completed'], true)) return $payment;
    }
    return null;
}

/**
 * Re-read an order until its payment settles. Card Field reports success as
 * soon as the payment is authorised; capture follows a few seconds later.
 */
function revolut_wait_for_order(RevolutClient $client, $orderId, $seconds = 12)
{
    $deadline = microtime(true) + $seconds;
    while (true) {
        $order = $client->get('/api/orders/' . rawurlencode($orderId));
        $state = $order['state'] ?? '';
        if (revolut_find_captured_payment($order) || in_array($state, ['completed', 'failed', 'cancelled'], true) || microtime(true) >= $deadline) {
            return $order;
        }
        usleep(1500000);
    }
}

/**
 * Finish a checkout session from a settled order: save the payment method and
 * record the invoice payment. Called by both complete.php and the webhook, so
 * whichever runs first does the work and the other finds it done.
 * Returns 'done', 'already' or 'pending'.
 */
function revolut_finalise_session($session, array $order)
{
    $payment = revolut_find_captured_payment($order);
    if (!$payment) return 'pending';
    $claimed = WHMCS\Database\Capsule::table('mod_revolut_sessions')
        ->where('token', $session->token)->whereNull('completed_at')
        ->update(['completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

    if ($claimed) {
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
            if ($session->pay_method_id) {
                updateCardPayMethod($session->client_id, $session->pay_method_id, $expiry, null, null, $remoteToken);
            } else {
                createCardPayMethod($session->client_id, 'revolut', $lastFour, $expiry, $brand, null, null, $remoteToken, 'billing', $methodType === 'revolut_pay' ? 'Revolut Pay' : 'Revolut - ' . $brand . '-' . $lastFour);
            }
        }
    }

    WHMCS\Database\Capsule::table('mod_revolut_transactions')->updateOrInsert(['payment_id' => $payment['id']], ['order_id' => $order['id'], 'invoice_id' => $session->invoice_id, 'updated_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
    if ($session->invoice_id && !WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $payment['id'])->exists()) {
        $currency = $payment['currency'] ?? $order['currency'];
        addInvoicePayment($session->invoice_id, $payment['id'], revolut_major_units($payment['amount'] ?? $order['amount'], $currency), revolut_payment_fee($payment, $currency), 'revolut');
    }
    return $claimed ? 'done' : 'already';
}
