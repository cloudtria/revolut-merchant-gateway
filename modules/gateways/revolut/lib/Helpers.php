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
    // Usually a code, but some calls (editing a pay method) pass a currency ID
    // or nothing at all; only accept a three-letter code here.
    $currency = $params['currency'] ?? '';
    if (is_array($currency)) $currency = $currency['code'] ?? '';
    if (is_object($currency)) $currency = $currency->code ?? '';
    $currency = strtoupper(trim((string) $currency));
    if (preg_match('/^[A-Z]{3}$/', $currency)) return $currency;

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

/**
 * WHMCS does not always pass full client details (editing an existing pay
 * method sends very little). Fill the gaps from the client record, found via
 * the details, the pay method's owner, or the logged-in client.
 */
function revolut_resolve_client(array $params)
{
    $details = (array) ($params['clientdetails'] ?? []);
    $clientId = (int) ($details['id'] ?? $details['userid'] ?? $details['client_id'] ?? $params['clientId'] ?? $params['userid'] ?? 0);
    if (!$clientId && !empty($params['paymethodid'])) {
        $clientId = (int) WHMCS\Database\Capsule::table('tblpaymethods')->where('id', (int) $params['paymethodid'])->value('userid');
    }
    if (!$clientId) {
        try { $client = class_exists('Auth') ? \Auth::client() : null; if ($client) $clientId = (int) $client->id; } catch (Throwable $e) {}
    }
    if (!$clientId && !empty($_SESSION['uid'])) $clientId = (int) $_SESSION['uid'];
    if (!$clientId) throw new RuntimeException('Could not determine the client for this payment method.');
    $row = WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$row) throw new RuntimeException('Client ' . $clientId . ' was not found.');
    $fromDb = [
        'id' => $clientId, 'firstname' => $row->firstname, 'lastname' => $row->lastname, 'email' => $row->email,
        'phonenumber' => $row->phonenumber, 'country' => $row->country, 'state' => $row->state, 'city' => $row->city,
        'postcode' => $row->postcode, 'address1' => $row->address1, 'address2' => $row->address2,
    ];
    foreach ($fromDb as $k => $v) {
        if (!isset($details[$k]) || trim((string) $details[$k]) === '') $details[$k] = $v;
    }
    $details['id'] = $clientId;
    $params['clientdetails'] = $details;
    return $params;
}

/** Re-read an order until the given payment leaves its in-flight states. */
function revolut_wait_for_payment(RevolutClient $client, $orderId, array $payment, $seconds = 10)
{
    $deadline = microtime(true) + $seconds;
    $final = ['captured', 'completed', 'declined', 'failed', 'cancelled'];
    while (!in_array($payment['state'] ?? '', $final, true) && microtime(true) < $deadline) {
        usleep(1500000);
        $order = $client->get('/api/orders/' . rawurlencode($orderId));
        foreach (($order['payments'] ?? []) as $p) {
            if (($p['id'] ?? '') === ($payment['id'] ?? '')) $payment = $p;
        }
    }
    return $payment;
}

/** Webhook events the module subscribes to. */
function revolut_webhook_events()
{
    return [
        'ORDER_COMPLETED', 'ORDER_FAILED', 'ORDER_CANCELLED', 'ORDER_PAYMENT_DECLINED', 'ORDER_PAYMENT_FAILED',
        'DISPUTE_ACTION_REQUIRED', 'DISPUTE_UNDER_REVIEW', 'DISPUTE_WON', 'DISPUTE_LOST',
    ];
}

/** The WHMCS ledger row (tblaccounts) for any of the given Revolut payment IDs. */
function revolut_ledger_payment(array $paymentIds)
{
    $paymentIds = array_values(array_filter(array_map('strval', $paymentIds)));
    if (!$paymentIds) return null;
    return WHMCS\Database\Capsule::table('tblaccounts')
        ->whereIn('transid', $paymentIds)->where('amountin', '>', 0)->orderBy('id')->first();
}

/**
 * Record a refund in WHMCS without sending it to the gateway or adding credit
 * (the money has already gone back to the customer through Revolut).
 */
function revolut_record_refund_in_whmcs($ledgerRow, $amount, $refundTransId, $note, $sendEmail)
{
    if (WHMCS\Database\Capsule::table('tblaccounts')->where('transid', $refundTransId)->exists()) return 'already recorded';
    $refunded = (float) WHMCS\Database\Capsule::table('tblaccounts')->where('refundid', $ledgerRow->id)->sum('amountout');
    $remaining = round((float) $ledgerRow->amountin - $refunded, 2);
    $amount = round(min((float) $amount, $remaining), 2);
    if ($amount <= 0) return 'nothing left to refund';
    if (!function_exists('refundInvoicePayment')) App::load_function('invoice');
    if (!function_exists('getCCVariables')) App::load_function('cc');
    $result = refundInvoicePayment($ledgerRow->id, $amount, false, false, $sendEmail, $refundTransId);
    if ($result !== 'success' && $result !== true) return 'failed: ' . (is_string($result) ? $result : json_encode($result));
    logActivity("Revolut: {$note} recorded on Invoice #{$ledgerRow->invoiceid} ({$amount}, {$refundTransId})", (int) $ledgerRow->userid);
    return 'recorded';
}

/**
 * A completed refund order. Refunds requested from WHMCS are already in the
 * ledger under the refund order ID; refunds made in the Revolut dashboard or
 * app are not, and are recorded here.
 */
function revolut_record_refund_order(RevolutClient $client, array $refund)
{
    if (($refund['state'] ?? '') !== 'completed') return 'refund not completed';
    $originalId = (string) ($refund['related_order_id'] ?? '');
    if ($originalId === '') return 'no related order';
    $original = $client->get('/api/orders/' . rawurlencode($originalId));
    $ledger = revolut_ledger_payment(array_column($original['payments'] ?? [], 'id'));
    if (!$ledger) return 'original payment is not in WHMCS';
    $currency = $refund['currency'] ?? $original['currency'] ?? '';
    return revolut_record_refund_in_whmcs($ledger, revolut_major_units($refund['amount'] ?? 0, $currency), (string) $refund['id'], 'refund made in Revolut', true);
}

/** Chargebacks: log when opened or won, record a refund when lost. */
function revolut_handle_dispute(RevolutClient $client, $disputeId, $eventName)
{
    $dispute = $client->get('/api/disputes/' . rawurlencode($disputeId));
    $payment = $dispute['payment'] ?? [];
    $ledger = revolut_ledger_payment([$payment['id'] ?? '']);
    $currency = $dispute['currency'] ?? $payment['currency'] ?? '';
    $amount = revolut_major_units($dispute['amount'] ?? 0, $currency);
    $where = $ledger ? "Invoice #{$ledger->invoiceid}" : 'payment ' . ($payment['id'] ?? '?') . ' (not in WHMCS)';
    $state = (string) ($dispute['state'] ?? '');
    $detail = trim(($dispute['reason_description'] ?? '') . ($state ? " [{$state}" . (!empty($dispute['substate']) ? '/' . $dispute['substate'] : '') . ']' : ''));
    if ($state === 'lost' && $ledger) {
        $result = revolut_record_refund_in_whmcs($ledger, $amount, 'chargeback-' . $disputeId, 'chargeback lost', false);
    } else {
        $due = !empty($dispute['response_due_date']) ? ', respond by ' . $dispute['response_due_date'] : '';
        logActivity("Revolut: chargeback {$state} on {$where}: {$amount} {$currency}{$due}. {$detail} (dispute {$disputeId})", $ledger ? (int) $ledger->userid : 0);
        $result = 'logged';
    }
    logTransaction('Revolut', ['event' => $eventName, 'dispute_id' => $disputeId, 'state' => $state, 'substate' => $dispute['substate'] ?? '', 'amount' => $amount, 'currency' => $currency, 'payment_id' => $payment['id'] ?? '', 'invoice_id' => $ledger ? (int) $ledger->invoiceid : 0, 'result' => $result], 'Chargeback ' . ($state ?: 'update'));
    return $result;
}
