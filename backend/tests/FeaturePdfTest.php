<?php

declare(strict_types=1);

namespace JutForm\Tests;

use JutForm\Tests\Support\IntegrationTestCase;

final class FeaturePdfTest extends IntegrationTestCase
{
    public function testOwnerCanExportPdf(): void
    {
        $this->loginAs('poweruser');

        $res = $this->get('/api/forms/1/export/pdf');
        $this->assertSame('raw', $res['type']);
        $this->assertSame(200, $res['status']);

        $headers = $res['headers'] ?? [];
        $this->assertIsArray($headers);
        $this->assertSame('application/pdf', $headers['Content-Type'] ?? null);
        $this->assertStringContainsString('.pdf', (string) ($headers['Content-Disposition'] ?? ''));

        $body = (string) ($res['body'] ?? '');
        $this->assertStringStartsWith('%PDF-', $body);
    }

    public function testUnknownOrUnauthorizedFormIsRejected(): void
    {
        $this->loginAs('bob');
        $res = $this->get('/api/forms/1/export/pdf');
        $this->assertSame(404, $res['status']);
    }
}
