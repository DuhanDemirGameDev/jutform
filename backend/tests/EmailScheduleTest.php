<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\Database;
use JutForm\Tests\Support\IntegrationTestCase;

final class EmailScheduleTest extends IntegrationTestCase
{
    public function testScheduleRequiresRecipient(): void
    {
        $this->loginAs('poweruser');
        $res = $this->postJson('/api/forms/1/scheduled-emails', [
            'subject' => 'Hi',
            'body' => 'Body',
        ]);
        $this->assertSame(400, $res['status']);
    }

    public function testScheduleCreatesRow(): void
    {
        $this->loginAs('poweruser');
        $res = $this->postJson('/api/forms/1/scheduled-emails', [
            'recipient_email' => 'integration-test@example.com',
            'subject' => 'Hello',
            'body' => 'Test body',
            'scheduled_at' => '2030-01-01 12:00:00',
        ]);
        $this->assertSame(201, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('id', $body);
    }

    public function testScheduleNormalizesUserLocalTimeToUtc(): void
    {
        $this->loginAs('alice');
        $res = $this->postJson('/api/forms/161/scheduled-emails', [
            'recipient_email' => 'integration-test@example.com',
            'subject' => 'Reminder',
            'body' => 'Timezone check',
            'scheduled_at' => '2030-01-01 15:00:00',
        ]);
        $this->assertSame(201, $res['status']);
        $body = $this->jsonBody($res);
        $emailId = (int) ($body['id'] ?? 0);
        $this->assertGreaterThan(0, $emailId);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT scheduled_at FROM scheduled_emails WHERE id = ?');
        $stmt->execute([$emailId]);
        $stored = $stmt->fetchColumn();
        $this->assertSame('2030-01-01 12:00:00', $stored);
    }

    public function testScheduleNotFoundForOtherUserForm(): void
    {
        $this->loginAs('bob');
        $res = $this->postJson('/api/forms/1/scheduled-emails', [
            'recipient_email' => 'x@example.com',
        ]);
        $this->assertSame(404, $res['status']);
    }
}
