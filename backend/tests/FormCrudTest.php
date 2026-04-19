<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Tests\Support\IntegrationTestCase;

final class FormCrudTest extends IntegrationTestCase
{
    public function testCreateValidationRequiresTitle(): void
    {
        $this->loginAs('alice');
        $res = $this->postJson('/api/forms', ['description' => 'no title']);
        $this->assertSame(400, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('error', $body);
    }

    public function testCreateReturns201(): void
    {
        $this->loginAs('alice');
        $title = 'PHPUnit Form ' . bin2hex(random_bytes(4));
        $res = $this->postJson('/api/forms', [
            'title' => $title,
            'description' => 'integration',
            'status' => 'draft',
            'fields' => [],
        ]);
        $this->assertSame(201, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame('created', $body['status']);
    }

    public function testNewlyCreatedFormCanBeEditedImmediately(): void
    {
        $this->loginAs('alice');
        $title = 'Immediate Edit ' . bin2hex(random_bytes(4));
        $created = $this->postJson('/api/forms', [
            'title' => $title,
            'description' => 'create/edit race check',
            'status' => 'draft',
            'fields' => [],
        ]);
        $this->assertSame(201, $created['status']);
        $body = $this->jsonBody($created);
        $formId = (int) ($body['id'] ?? 0);
        $this->assertGreaterThan(0, $formId);

        $edit = $this->get('/api/forms/' . $formId . '/edit');
        $this->assertSame(200, $edit['status']);
        $payload = $this->jsonBody($edit);
        $this->assertSame($formId, (int) $payload['form']['id']);
        $this->assertArrayHasKey('resources', $payload);
        $this->assertNotEmpty($payload['resources']);
    }

    public function testListReturnsFormsForCurrentUser(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/forms');
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('forms', $body);
        $this->assertArrayHasKey('page', $body);
        $this->assertArrayHasKey('limit', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertGreaterThan(0, \count($body['forms']));
        $first = $body['forms'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('submission_count', $first);
        $this->assertArrayHasKey('last_submission_at', $first);
        $this->assertArrayHasKey('owner_display_name', $first);
    }

    public function testListSupportsPagination(): void
    {
        $this->loginAs('alice');

        for ($i = 1; $i <= 3; $i++) {
            $res = $this->postJson('/api/forms', [
                'title' => 'Paged Form ' . $i . ' ' . bin2hex(random_bytes(3)),
                'description' => 'pagination check',
                'status' => 'draft',
                'fields' => [],
            ]);
            $this->assertSame(201, $res['status']);
        }

        $pageOne = $this->get('/api/forms', ['page' => 1, 'limit' => 2]);
        $this->assertSame(200, $pageOne['status']);
        $firstBody = $this->jsonBody($pageOne);
        $this->assertCount(2, $firstBody['forms']);
        $this->assertSame(1, $firstBody['page']);
        $this->assertSame(2, $firstBody['limit']);
        $this->assertGreaterThanOrEqual(3, $firstBody['total']);

        $pageTwo = $this->get('/api/forms', ['page' => 2, 'limit' => 2]);
        $this->assertSame(200, $pageTwo['status']);
        $secondBody = $this->jsonBody($pageTwo);
        $this->assertNotEmpty($secondBody['forms']);
        $this->assertSame(2, $secondBody['page']);
        $this->assertSame(2, $secondBody['limit']);
        $this->assertSame($firstBody['total'], $secondBody['total']);
    }

    public function testShowOwnedForm(): void
    {
        $this->loginAs('poweruser');
        $res = $this->get('/api/forms/1');
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertArrayHasKey('form', $body);
        $this->assertArrayHasKey('settings', $body);
        $this->assertArrayHasKey('resources', $body);
        $this->assertSame(1, (int) $body['form']['id']);
    }

    public function testShowOtherUserFormNotFound(): void
    {
        $this->loginAs('bob');
        $res = $this->get('/api/forms/1');
        $this->assertSame(404, $res['status']);
    }

    public function testUpdateOwnedForm(): void
    {
        $this->loginAs('poweruser');
        $res = $this->putJson('/api/forms/1', [
            'title' => 'Power form 1',
            'settings' => ['theme_preference' => 'light'],
        ]);
        $this->assertSame(200, $res['status']);
        $body = $this->jsonBody($res);
        $this->assertTrue($body['ok'] ?? false);

        $show = $this->get('/api/forms/1');
        $payload = $this->jsonBody($show);
        $this->assertSame('light', $payload['settings']['theme_preference'] ?? '');
    }

    public function testUpdatePreservesLongNotificationTemplate(): void
    {
        $this->loginAs('poweruser');

        $template = implode('', [
            '<html><body><h1>Welcome</h1><p>Thanks for registering for our event.</p>',
            '<p>Support: <a href="mailto:support@example.com">support@example.com</a></p>',
            '<p>Team signature block with branding and links repeated for length.</p>',
            str_repeat('<div class="footer">See you soon.</div>', 12),
            '</body></html>',
        ]);
        $this->assertGreaterThan(255, strlen($template));

        $res = $this->putJson('/api/forms/1', [
            'settings' => [
                'notification_email_template' => $template,
            ],
        ]);
        $this->assertSame(200, $res['status']);

        $show = $this->get('/api/forms/1');
        $this->assertSame(200, $show['status']);
        $payload = $this->jsonBody($show);
        $this->assertSame($template, $payload['settings']['notification_email_template'] ?? null);
    }
}
