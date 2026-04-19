<?php

namespace JutForm\Services;

use JutForm\Core\RedisClient;
use JutForm\Models\ConfigRepository;
use RuntimeException;

class PaymentGatewayService
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $resolved = $baseUrl ?: (getenv('PAYMENT_GATEWAY_URL') ?: 'http://payment-gateway');
        $resolved = rtrim($resolved, '/');
        if ($resolved === 'http://localhost' || $resolved === 'http://127.0.0.1') {
            $resolved = 'http://payment-gateway';
        }
        $this->baseUrl = $resolved;
    }

    public function fetchSalt(): string
    {
        $response = $this->requestJson('GET', '/salt');
        if ($response['status'] !== 200) {
            throw new RuntimeException('gateway_unavailable');
        }
        $salt = $response['body']['salt'] ?? null;
        if (!is_string($salt) || $salt === '') {
            throw new RuntimeException('invalid_salt_response');
        }
        return $salt;
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function charge(int $userId, string $amount, string $datetimeUtc, string $hash): array
    {
        $response = $this->requestJson('POST', '/charge', [
            'hash' => $hash,
            'user_id' => $userId,
            'amount' => (float) $amount,
            'datetime' => $datetimeUtc,
        ]);

        if (!in_array($response['status'], [200, 402], true)) {
            throw new RuntimeException('gateway_unavailable');
        }

        $status = $response['body']['status'] ?? null;
        if (!is_string($status)) {
            throw new RuntimeException('invalid_charge_response');
        }
        if ($response['status'] === 200 && $status !== 'approved') {
            throw new RuntimeException('invalid_charge_response');
        }
        if ($response['status'] === 402 && $status !== 'declined') {
            throw new RuntimeException('invalid_charge_response');
        }

        return $response;
    }

    private function apiKey(): string
    {
        $cacheKey = 'payment:api_key:v1';
        try {
            $redis = RedisClient::getInstance();
            $cached = $redis->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable) {
        }

        $apiKey = ConfigRepository::get('payment_api_key');
        if ($apiKey === null || $apiKey === '') {
            throw new RuntimeException('missing_api_key');
        }

        try {
            $redis = RedisClient::getInstance();
            $redis->setex($cacheKey, 300, $apiKey);
        } catch (\Throwable) {
        }

        return $apiKey;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>}
     */
    private function requestJson(string $method, string $path, ?array $payload = null): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey(),
        ];
        $opts = [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ];
        if ($payload !== null) {
            $opts['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $opts['header'] .= "Content-Type: application/json\r\n";
        }
        $ctx = stream_context_create(['http' => $opts]);
        $raw = @file_get_contents($this->baseUrl . $path, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('gateway_unavailable');
        }

        $status = 0;
        $responseHeader = $http_response_header[0] ?? null;
        if (is_string($responseHeader) && preg_match('/HTTP\/\S+\s+(\d+)/', $responseHeader, $m)) {
            $status = (int) $m[1];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('invalid_json_response');
        }

        return [
            'status' => $status,
            'body' => $decoded,
        ];
    }
}
