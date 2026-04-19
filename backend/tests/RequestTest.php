<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testIpPrefersForwardedForWhenPresent(): void
    {
        $request = Request::create('POST', '/api/forms/1/submissions', [], null, [
            'X-Forwarded-For' => '203.0.113.10, 172.18.0.2',
        ], [
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 172.18.0.2',
        ]);

        $this->assertSame('203.0.113.10', $request->ip());
    }

    public function testIpFallsBackToRemoteAddrWhenForwardedForIsInvalid(): void
    {
        $request = Request::create('POST', '/api/forms/1/submissions', [], null, [
            'X-Forwarded-For' => 'not-an-ip',
        ], [
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
        ]);

        $this->assertSame('198.51.100.20', $request->ip());
    }
}
