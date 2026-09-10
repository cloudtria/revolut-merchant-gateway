<?php
namespace Cloudtria\WHMCS\Revolut;

class RevolutException extends \RuntimeException
{
    private $response;
    private $statusCode;

    public function __construct($message, $statusCode = 0, array $response = [])
    {
        parent::__construct($message, (int) $statusCode);
        $this->statusCode = (int) $statusCode;
        $this->response = $response;
    }

    public function getResponse() { return $this->response; }
    public function getStatusCode() { return $this->statusCode; }
}

class RevolutClient
{
    private $secretKey;
    private $apiVersion;
    private $baseUrl;
    private $debug;

    public function __construct(array $config)
    {
        $this->secretKey = trim((string) ($config['secretKey'] ?? ''));
        $this->apiVersion = trim((string) ($config['apiVersion'] ?? '2026-08-17'));
        $environment = (string) ($config['environment'] ?? 'sandbox');
        $this->baseUrl = $environment === 'production'
            ? 'https://merchant.revolut.com'
            : 'https://sandbox-merchant.revolut.com';
        $this->debug = !empty($config['debug']);

        if ($this->secretKey === '') {
            throw new RevolutException('Revolut secret key is not configured.');
        }
    }

    public function createCustomer(array $payload)
    {
        return $this->request('POST', '/api/customers', $payload);
    }

    public function getCustomer($customerId)
    {
        return $this->request('GET', '/api/customers/' . rawurlencode($customerId));
    }

    public function getCustomerPaymentMethods($customerId)
    {
        return $this->request('GET', '/api/customers/' . rawurlencode($customerId) . '/payment-methods');
    }

    public function createOrder(array $payload, $idempotencyKey = null)
    {
        return $this->request('POST', '/api/orders', $payload, $idempotencyKey);
    }

    public function getOrder($orderId)
    {
        return $this->request('GET', '/api/orders/' . rawurlencode($orderId));
    }

    public function payOrder($orderId, array $payload, $idempotencyKey = null)
    {
        return $this->request('POST', '/api/orders/' . rawurlencode($orderId) . '/payments', $payload, $idempotencyKey);
    }

    public function getPayment($paymentId)
    {
        return $this->request('GET', '/api/payments/' . rawurlencode($paymentId));
    }

    public function refundOrder($orderId, array $payload, $idempotencyKey = null)
    {
        return $this->request('POST', '/api/orders/' . rawurlencode($orderId) . '/refund', $payload, $idempotencyKey);
    }

    public function createWebhook(array $payload, $idempotencyKey = null)
    {
        return $this->request('POST', '/api/webhooks', $payload, $idempotencyKey);
    }

    private function request($method, $path, array $payload = null, $idempotencyKey = null)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Revolut-Api-Version: ' . $this->apiVersion,
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RevolutException('Revolut API transport error: ' . $error, 0, []);
        }

        $decoded = [];
        if ($raw !== '' && $raw !== false) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = ['raw' => $raw];
            }
        }

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['message']) ? $decoded['message'] : ('Revolut API HTTP ' . $status);
            throw new RevolutException($message, $status, $decoded);
        }

        return $decoded;
    }
}
