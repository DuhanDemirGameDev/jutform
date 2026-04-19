<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Core\Database;
use JutForm\Core\RedisClient;
use JutForm\Models\KeyValueStore;
use JutForm\Core\Request;
use JutForm\Tests\Support\IntegrationTestCase;

final class SubmissionTest extends IntegrationTestCase
{
    public function testCreatePublicSubmission(): void
    {
        $body = json_encode(['data' => ['test_key' => 'test_value']], JSON_THROW_ON_ERROR);
        $req = Request::create('POST', '/api/forms/1/submissions', [], $body, [
            'Content-Type' => 'application/json',
        ], [
            'REMOTE_ADDR' => '198.51.100.12',
        ]);
        $res = $this->dispatch($req);
        $this->assertSame(201, $res['status']);
        $payload = $this->jsonBody($res);
        $this->assertArrayHasKey('id', $payload);
    }

    public function testIndexRequiresOwnerOrSharedUser(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/forms/1/submissions', ['page' => 1, 'limit' => 5]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('submissions', $body);
        $this->assertArrayHasKey('page', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('related_forms', $body);
    }

    public function testIndexForbiddenForNonCollaborator(): void
    {
        $this->loginAs('bob');
        $res = $this->get('/api/forms/1/submissions');
        $this->assertSame(403, $res['status']);
    }

    public function testSharedViewerKeepsOwnSidebarScope(): void
    {
        $this->loginAs('alice');
        $title = 'Alice Sidebar Form ' . bin2hex(random_bytes(4));
        $created = $this->postJson('/api/forms', [
            'title' => $title,
            'description' => 'sidebar scope regression',
            'status' => 'draft',
            'fields' => [],
        ]);
        $this->assertSame(201, $created['status']);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute(['alice']);
        $aliceId = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $aliceId);

        KeyValueStore::set(1, 'shared_with_user_ids', json_encode([$aliceId], JSON_THROW_ON_ERROR));

        $res = $this->get('/api/forms/1/submissions', ['page' => 1, 'limit' => 5]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $related = $body['related_forms'] ?? [];
        $this->assertIsArray($related);

        $titles = array_column($related, 'title');
        $this->assertContains($title, $titles);
    }

    public function testExportCsvRequiresAuth(): void
    {
        $res = $this->get('/api/forms/1/submissions/export');
        $this->assertSame(401, $res['status']);
    }

    public function testExportCsvOwnerReturnsAttachment(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/forms/1/submissions/export');
        $this->assertSame('csv', $res['type']);
        $csv = (string) ($res['body'] ?? '');
        $this->assertStringContainsString('id', $csv);
        $this->assertStringContainsString('data_json', $csv);
    }

    public function testExportCsvIncludesUtf8BomAndPreservesUnicode(): void
    {
        $this->loginAs('poweruser');

        $payload = json_encode([
            'name' => 'José 😀',
            'city' => 'İstanbul',
            'script' => '東京',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO submissions (form_id, data_json, ip_address, submitted_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([1, $payload, '127.0.0.1', '2030-01-01 12:00:00']);

        $res = $this->get('/api/forms/1/submissions/export');
        $this->assertSame('csv', $res['type']);
        $csv = (string) ($res['body'] ?? '');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('José 😀', $csv);
        $this->assertStringContainsString('İstanbul', $csv);
        $this->assertStringContainsString('東京', $csv);
    }

    public function testAdjacentPagesDoNotOverlapWhenSubmittedAtTies(): void
    {
        $this->loginAs('poweruser');
        $this->clearSubmissionSnapshot($this->userIdFor('poweruser'), 1);

        $pdo = Database::getInstance();
        $timestamp = '2030-01-01 12:00:00';
        $ids = [];
        $stmt = $pdo->prepare(
            'INSERT INTO submissions (form_id, data_json, ip_address, submitted_at) VALUES (?, ?, ?, ?)'
        );

        for ($i = 1; $i <= 4; $i++) {
            $stmt->execute([1, json_encode(['n' => $i], JSON_THROW_ON_ERROR), '127.0.0.1', $timestamp]);
            $ids[] = (int) $pdo->lastInsertId();
        }

        rsort($ids);

        $pageOne = $this->get('/api/forms/1/submissions', ['page' => 1, 'limit' => 2]);
        $this->assertSame(200, $pageOne['status']);
        $firstBody = $this->jsonBody($pageOne);
        $firstIds = array_map(static fn (array $row): int => (int) $row['id'], $firstBody['submissions']);

        $pageTwo = $this->get('/api/forms/1/submissions', ['page' => 2, 'limit' => 2]);
        $this->assertSame(200, $pageTwo['status']);
        $secondBody = $this->jsonBody($pageTwo);
        $secondIds = array_map(static fn (array $row): int => (int) $row['id'], $secondBody['submissions']);

        $this->assertSame(array_slice($ids, 0, 2), $firstIds);
        $this->assertSame(array_slice($ids, 2, 2), $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    public function testLaterPagesUseSnapshotAndIgnoreNewerInsertions(): void
    {
        $this->loginAs('poweruser');
        $this->clearSubmissionSnapshot($this->userIdFor('poweruser'), 1);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO submissions (form_id, data_json, ip_address, submitted_at) VALUES (?, ?, ?, ?)'
        );
        $baseTimes = [
            '2030-01-01 12:00:03',
            '2030-01-01 12:00:02',
            '2030-01-01 12:00:01',
        ];
        $ids = [];
        foreach ($baseTimes as $index => $time) {
            $stmt->execute([1, json_encode(['seed' => $index], JSON_THROW_ON_ERROR), '127.0.0.1', $time]);
            $ids[] = (int) $pdo->lastInsertId();
        }

        $pageOne = $this->get('/api/forms/1/submissions', ['page' => 1, 'limit' => 2]);
        $this->assertSame(200, $pageOne['status']);
        $firstBody = $this->jsonBody($pageOne);
        $firstIds = array_map(static fn (array $row): int => (int) $row['id'], $firstBody['submissions']);

        $stmt->execute([1, json_encode(['seed' => 'new'], JSON_THROW_ON_ERROR), '127.0.0.1', '2030-01-01 12:00:04']);
        $newId = (int) $pdo->lastInsertId();

        $pageTwo = $this->get('/api/forms/1/submissions', ['page' => 2, 'limit' => 2]);
        $this->assertSame(200, $pageTwo['status']);
        $secondBody = $this->jsonBody($pageTwo);
        $secondIds = array_map(static fn (array $row): int => (int) $row['id'], $secondBody['submissions']);

        $this->assertNotContains($newId, $secondIds);
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));
    }

    private function clearSubmissionSnapshot(int $userId, int $formId): void
    {
        $redis = RedisClient::getInstance();
        $redis->del('submissions:snapshot:' . $userId . ':' . $formId);
    }

    private function userIdFor(string $username): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        return (int) $stmt->fetchColumn();
    }
}
