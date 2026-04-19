<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\Database;
use JutForm\Core\Request;
use JutForm\Tests\Support\IntegrationTestCase;

final class AdminTest extends IntegrationTestCase
{
    public function testRevenueForbiddenForRegularUser(): void
    {
        $this->loginAs('alice');
        $res = $this->get('/api/admin/revenue');
        $this->assertSame(403, $res['status']);
    }

    public function testRevenueAdminOk(): void
    {
        $this->loginAs('admin');
        $res = $this->get('/api/admin/revenue');
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('revenue_total', $body);
        $this->assertIsFloat($body['revenue_total']);
    }

    public function testRevenueUsesApprovedPaymentsOnly(): void
    {
        $pdo = Database::getInstance();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO payments (user_id, amount, transaction_id, status, gateway_hash, paid_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, '49.99', 'txn-approved-1', 'approved', 'hash-approved-1', $now]);
        $stmt->execute([1, '10.00', 'txn-declined-1', 'declined', 'hash-declined-1', $now]);
        $stmt->execute([1, '25.01', 'txn-approved-2', 'approved', 'hash-approved-2', $now]);

        $this->loginAs('admin');
        $res = $this->get('/api/admin/revenue');
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame(75.0, $body['revenue_total']);
    }

    public function testInternalConfigFromLoopback(): void
    {
        $req = Request::create('GET', '/internal/admin/config', [], null, [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $res = $this->dispatch($req);
        $this->assertSame(401, $res['status']);
    }

    public function testInternalConfigRequiresAdminNotLoopbackTrust(): void
    {
        $this->loginAs('admin');
        $req = Request::create('GET', '/internal/admin/config', [], null, [], [
            'REMOTE_ADDR' => '8.8.8.8',
        ]);
        $res = $this->dispatch($req);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('items', $body);
        $this->assertIsArray($body['items']);
    }

    public function testLogsRequiresAdmin(): void
    {
        $this->loginAs('admin');
        $res = $this->get('/admin/logs');
        $this->assertSame('html', $res['type']);
        $this->assertSame(200, $res['status']);
        $this->assertIsString($res['body'] ?? '');
    }
}
