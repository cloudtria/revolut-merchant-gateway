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
            $table->timestamps();
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
