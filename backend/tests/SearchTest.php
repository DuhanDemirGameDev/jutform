<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Tests\Support\IntegrationTestCase;

/**
 * Keep this suite focused on representative search requests.
 */
final class SearchTest extends IntegrationTestCase
{
    public function testEmptyQueryReturnsEmpty(): void
    {
        $this->loginAs('alice');
        $res = $this->get('/api/search', ['q' => '']);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame([], $body['results']);
    }

    public function testBasicSearchReturnsJson(): void
    {
        $this->loginAs('alice');
        $unique = 'Alpha Search ' . bin2hex(random_bytes(4));
        $created = $this->postJson('/api/forms', [
            'title' => $unique,
            'description' => 'search performance regression guard',
            'status' => 'draft',
            'fields' => [],
        ]);
        $this->assertSame(201, $created['status']);

        $res = $this->get('/api/search', ['q' => $unique]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('results', $body);
        $this->assertIsArray($body['results']);
        $this->assertNotEmpty($body['results']);
        $this->assertSame($unique, $body['results'][0]['title'] ?? null);
    }

    public function testBasicSearchIsScopedToCurrentUserForms(): void
    {
        $unique = 'Scoped Search ' . bin2hex(random_bytes(4));

        $this->loginAs('alice');
        $aliceCreated = $this->postJson('/api/forms', [
            'title' => $unique,
            'description' => 'alice form',
            'status' => 'draft',
            'fields' => [],
        ]);
        $this->assertSame(201, $aliceCreated['status']);

        $this->logout();
        $this->loginAs('bob');
        $res = $this->get('/api/search', ['q' => $unique]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame([], $body['results']);
    }

    public function testAdvancedSearchSafeTitleTerm(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/search/advanced', [
            'field' => 'title',
            'term' => 'Power form',
        ]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('forms', $body);
        $this->assertIsArray($body['forms']);
    }

    public function testAdvancedSearchRejectsInjectedField(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/search/advanced', [
            'field' => 'title OR 1=1',
            'term' => 'Power form',
        ]);
        $this->assertSame(400, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertSame('Invalid search field', $body['error']);
    }
}
