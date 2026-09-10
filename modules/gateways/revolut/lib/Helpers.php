<?php
namespace Cloudtria\WHMCS\Revolut;

use WHMCS\Database\Capsule;

class Helpers
{
    public static function ensureSchema()
    {
        if (!Capsule::schema()->hasTable('mod_revolut_customers')) {
            Capsule::schema()->create('mod_revolut_customers', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('whmcs_client_id')->unique();
                $table->string('revolut_customer_id', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public static function getOrCreateCustomer(RevolutClient $client, $whmcsClientId, array $clientDetails)
    {
        self::ensureSchema();
        $row = Capsule::table('mod_revolut_customers')->where('whmcs_client_id', (int) $whmcsClientId)->first();
        if ($row && !empty($row->revolut_customer_id)) {
            return (string) $row->revolut_customer_id;
        }

        $payload = [
            'email' => (string) $clientDetails['email'],
        ];
        $fullName = trim(((string) ($clientDetails['firstname'] ?? '')) . ' ' . ((string) ($clientDetails['lastname'] ?? '')));
        if ($fullName !== '') $payload['full_name'] = $fullName;
        $phone = trim((string) ($clientDetails['phonenumber'] ?? ''));
        if ($phone !== '') $payload['phone'] = $phone;

        $customer = $client->createCustomer($payload);
        if (empty($customer['id'])) {
            throw new RevolutException('Revolut did not return a customer ID.');
        }

        Capsule::table('mod_revolut_customers')->updateOrInsert(
            ['whmcs_client_id' => (int) $whmcsClientId],
            [
                'revolut_customer_id' => $customer['id'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
        return (string) $customer['id'];
    }

    public static function tokenEncode($customerId, $paymentMethodId)
    {
        return 'rv1:' . $customerId . ':' . $paymentMethodId;
    }

    public static function tokenDecode($token)
    {
        $parts = explode(':', (string) $token, 3);
        if (count($parts) !== 3 || $parts[0] !== 'rv1' || !$parts[1] || !$parts[2]) {
            throw new \InvalidArgumentException('Invalid Revolut remote token.');
        }
        return ['customer_id' => $parts[1], 'payment_method_id' => $parts[2]];
    }

    public static function minorUnits($amount, $currency)
    {
        $currency = strtoupper((string) $currency);
        $zero = ['BIF','CLP','DJF','GNF','ISK','JPY','KMF','KRW','PYG','RWF','UGX','UYI','VND','VUV','XAF','XOF','XPF'];
        $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
        $exp = in_array($currency, $zero, true) ? 0 : (in_array($currency, $three, true) ? 3 : 2);
        return (int) round(((float) $amount) * (10 ** $exp));
    }

    public static function majorUnits($minor, $currency)
    {
        $currency = strtoupper((string) $currency);
        $zero = ['BIF','CLP','DJF','GNF','ISK','JPY','KMF','KRW','PYG','RWF','UGX','UYI','VND','VUV','XAF','XOF','XPF'];
        $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
        $exp = in_array($currency, $zero, true) ? 0 : (in_array($currency, $three, true) ? 3 : 2);
        return ((float) $minor) / (10 ** $exp);
    }

    public static function signContext(array $payload, $secret)
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, hash('sha256', (string) $secret, true));
        return $body . '.' . $sig;
    }

    public static function verifyContext($value, $secret, $maxAge = 900)
    {
        $parts = explode('.', (string) $value, 2);
        if (count($parts) !== 2) throw new \RuntimeException('Malformed context.');
        list($body, $sig) = $parts;
        $expected = hash_hmac('sha256', $body, hash('sha256', (string) $secret, true));
        if (!hash_equals($expected, $sig)) throw new \RuntimeException('Invalid context signature.');
        $padded = strtr($body, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad) $padded .= str_repeat('=', 4 - $pad);
        $payload = json_decode(base64_decode($padded), true);
        if (!is_array($payload)) throw new \RuntimeException('Invalid context payload.');
        if (empty($payload['ts']) || abs(time() - (int) $payload['ts']) > $maxAge) throw new \RuntimeException('Expired context.');
        return $payload;
    }

    public static function findSuccessfulPayment(array $order)
    {
        $payments = isset($order['payments']) && is_array($order['payments']) ? $order['payments'] : [];
        $acceptable = ['captured','completed','authorised','authorisation_passed'];
        for ($i = count($payments) - 1; $i >= 0; $i--) {
            if (in_array((string) ($payments[$i]['state'] ?? ''), $acceptable, true)) return $payments[$i];
        }
        return null;
    }

    public static function findPaymentMethod(RevolutClient $client, $customerId, $paymentMethodId = null)
    {
        $response = $client->getCustomerPaymentMethods($customerId);
        $methods = isset($response['payment_methods']) && is_array($response['payment_methods']) ? $response['payment_methods'] : [];
        if ($paymentMethodId) {
            foreach ($methods as $method) {
                if (($method['id'] ?? null) === $paymentMethodId) return $method;
            }
        }
        for ($i = count($methods) - 1; $i >= 0; $i--) {
            if (($methods[$i]['type'] ?? '') === 'card' && ($methods[$i]['saved_for'] ?? '') === 'merchant') return $methods[$i];
        }
        return null;
    }

    public static function cardType($brand)
    {
        $b = strtolower((string) $brand);
        if (strpos($b, 'visa') !== false) return 'Visa';
        if (strpos($b, 'mastercard') !== false) return 'MasterCard';
        if (strpos($b, 'amex') !== false || strpos($b, 'american_express') !== false) return 'American Express';
        if (strpos($b, 'discover') !== false) return 'Discover';
        return ucfirst(str_replace('_', ' ', $b ?: 'Card'));
    }

    public static function expiryMmyy(array $method)
    {
        $m = isset($method['expiry_month']) ? (int) $method['expiry_month'] : 0;
        $y = isset($method['expiry_year']) ? (int) $method['expiry_year'] : 0;
        if (!$m || !$y) return '';
        return sprintf('%02d%02d', $m, $y % 100);
    }

    public static function sanitize(array $data)
    {
        $blocked = ['authorization','secret','secret_key','webhook_secret','cvv','cvc','card_number','pan'];
        $walk = function ($value) use (&$walk, $blocked) {
            if (!is_array($value)) return $value;
            $out = [];
            foreach ($value as $k => $v) {
                $lk = strtolower((string) $k);
                $redact = false;
                foreach ($blocked as $needle) if (strpos($lk, $needle) !== false) $redact = true;
                $out[$k] = $redact ? '[REDACTED]' : $walk($v);
            }
            return $out;
        };
        return $walk($data);
    }
}
