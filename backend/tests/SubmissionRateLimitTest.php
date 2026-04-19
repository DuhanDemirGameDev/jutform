<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\RedisClient;
use JutForm\Middleware\SubmissionRateLimitMiddleware;
use JutForm\Tests\Support\IntegrationTestCase;

final class SubmissionRateLimitTest extends IntegrationTestCase
{
    public function testEleventhSubmissionFromSameIpIsRateLimited(): void
    {
        $ip = '198.51.100.77';
        $this->purgeRateLimitKeys($ip);
        try {
            for ($i = 1; $i <= 10; $i++) {
                $res = $this->postSubmissionFromIp($ip, [
                    'submitter_name' => 'QA ' . $i,
                    'email' => 'qa' . $i . '@example.test',
                ]);
                $this->assertSame(201, $res['status'], 'Request ' . $i . ' should be allowed');
            }

            $blocked = $this->postSubmissionFromIp($ip, [
                'submitter_name' => 'QA 11',
                'email' => 'qa11@example.test',
            ]);

            $this->assertSame('json', $blocked['type']);
            $this->assertSame(429, $blocked['status']);
            $body = $this->jsonBody($blocked);
            $this->assertSame('Too Many Requests', $body['error']);

            $headers = $blocked['headers'] ?? [];
            $this->assertIsArray($headers);
            $this->assertArrayHasKey('Retry-After', $headers);
            $this->assertMatchesRegularExpression('/^[1-9]\d*$/', (string) $headers['Retry-After']);
        } finally {
            $this->purgeRateLimitKeys($ip);
        }
    }

    public function testOtherEndpointsAreNotAffectedBySubmissionRateLimit(): void
    {
        $ip = '198.51.100.78';
        $this->purgeRateLimitKeys($ip);
        try {
            for ($i = 1; $i <= 11; $i++) {
                $this->postSubmissionFromIp($ip, [
                    'submitter_name' => 'Burst ' . $i,
                    'email' => 'burst' . $i . '@example.test',
                ]);
            }

            $this->loginAs('poweruser');
            $res = $this->get('/api/forms', [], [
                'REMOTE_ADDR' => $ip,
            ]);

            $this->assertSame(200, $res['status']);
            $body = $this->jsonBody($res);
            $this->assertArrayHasKey('forms', $body);
        } finally {
            $this->purgeRateLimitKeys($ip);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postSubmissionFromIp(string $ip, array $data): array
    {
        return $this->postJson('/api/forms/1/submissions', [
            'data' => $data,
        ], [
            'REMOTE_ADDR' => $ip,
        ]);
    }

    private function purgeRateLimitKeys(string $ip): void
    {
        $redis = RedisClient::getInstance();
        $now = time();
        foreach ([$now - 5, $now, $now + 5] as $timestamp) {
            $redis->del(SubmissionRateLimitMiddleware::keyForIp($ip, $timestamp));
        }
    }
}
