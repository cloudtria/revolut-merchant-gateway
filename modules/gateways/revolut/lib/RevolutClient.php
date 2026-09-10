<?php

final class RevolutClient
{
    private $baseUrl;
    private $secretKey;
    private $apiVersion;

    public function __construct(array $params)
    {
        $this->baseUrl = ($params['environment'] ?? 'sandbox') === 'production'
            ? 'https://merchant.revolut.com'
            : 'https://sandbox-merchant.revolut.com';
        $this->secretKey = trim((string) ($params['secretKey'] ?? ''));
        $this->apiVersion = trim((string) ($params['apiVersion'] ?? '2026-08-17'));
        if ($this->secretKey === '') {
            throw new RuntimeException('The Revolut secret key is not configured.');
        }
    }

    public function get($path)
    {
        return $this->request('GET', $path);
    }

    public function post($path, array $body, $idempotencyKey = null)
    {
        return $this->request('POST', $path, $body, $idempotencyKey);
    }

    private function request($method, $path, $body = null, $idempotencyKey = null)
    {
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Accept: application/json',
            'Content-Type: application/json',
            'Revolut-Api-Version: ' . $this->apiVersion,
        ];
        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Unable to reach Revolut: ' . $error);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }
        if ($status < 200 || $status >= 300) {
            $message = $data['message'] ?? $data['code'] ?? ('HTTP ' . $status);
            throw new RevolutApiException('Revolut rejected the request: ' . $message, $status, $data);
        }
        return $data;
    }
}

final class RevolutApiException extends RuntimeException
{
    public $response;
    public function __construct($message, $status, array $response)
    {
        parent::__construct($message, (int) $status);
        $this->response = $response;
    }
}
