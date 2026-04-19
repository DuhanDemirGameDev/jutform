<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\Database;
use JutForm\Tests\Support\IntegrationTestCase;

final class PaymentTest extends IntegrationTestCase
{
    public function testPaymentRequiresAmountAndFormId(): void
    {
        $this->loginAs('poweruser');
        $res = $this->postJson('/api/payments', ['form_id' => 1]);
        $this->assertSame(400, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('error', $body);
    }

    public function testApprovedPaymentPersistsAndReturns200(): void
    {
        $this->loginAs('poweruser');
        $res = $this->postJson('/api/payments', [
            'form_id' => 1,
            'amount' => 49.99,
        ]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame('approved', $body['status']);
        $this->assertArrayHasKey('transaction_id', $body);

        $pdo = Database::getInstance();
        $stmt = $pdo->query('SELECT user_id, amount, transaction_id, status, gateway_hash FROM payments ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('approved', $row['status']);
        $this->assertSame('49.99', number_format((float) $row['amount'], 2, '.', ''));
        $this->assertNotEmpty($row['transaction_id']);
        $this->assertSame(64, strlen((string) $row['gateway_hash']));
    }

    public function testDeclinedPaymentPersistsAndReturns402(): void
    {
        $this->loginAs('poweruser');
        $res = $this->postJson('/api/payments', [
            'form_id' => 1,
            'amount' => 0.5,
        ]);
        $this->assertSame(402, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame('declined', $body['status']);
        $this->assertArrayHasKey('reason', $body);

        $pdo = Database::getInstance();
        $stmt = $pdo->query('SELECT amount, transaction_id, status, gateway_hash FROM payments ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('declined', $row['status']);
        $this->assertSame('0.50', number_format((float) $row['amount'], 2, '.', ''));
        $this->assertNull($row['transaction_id']);
        $this->assertSame(64, strlen((string) $row['gateway_hash']));
    }

    public function testGatewayUnavailableReturns503(): void
    {
        $this->loginAs('poweruser');
        $previous = getenv('PAYMENT_GATEWAY_URL');
        putenv('PAYMENT_GATEWAY_URL=http://127.0.0.1:1');
        try {
            $res = $this->postJson('/api/payments', [
                'form_id' => 1,
                'amount' => 49.99,
            ]);
            $this->assertSame(503, $res['status']);
            $body = $this->jsonBody($res);
            $this->assertArrayHasKey('error', $body);
        } finally {
            if ($previous === false) {
                putenv('PAYMENT_GATEWAY_URL');
            } else {
                putenv('PAYMENT_GATEWAY_URL=' . $previous);
            }
        }
    }
}
