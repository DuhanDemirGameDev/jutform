<?php

namespace JutForm\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use JutForm\Core\Database;
use JutForm\Core\Request;
use JutForm\Core\RequestContext;
use JutForm\Core\Response;
use JutForm\Models\Form;
use JutForm\Models\User;

class EmailController
{
    public function schedule(Request $request, string $id): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $form = Form::find((int) $id);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }
        $body = $request->jsonBody();
        $to = (string) ($body['recipient_email'] ?? '');
        $subject = (string) ($body['subject'] ?? 'Notification');
        $text = (string) ($body['body'] ?? '');
        $when = (string) ($body['scheduled_at'] ?? '');
        if ($to === '') {
            Response::error('recipient_email required', 400);
        }
        $scheduledAt = $this->normalizeScheduledAt($uid, $when);
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO scheduled_emails (form_id, recipient_email, subject, body, scheduled_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([(int) $id, $to, $subject, $text, $scheduledAt, 'pending', $now]);
        Response::json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    private function normalizeScheduledAt(int $userId, string $scheduledAt): string
    {
        $utc = new DateTimeZone('UTC');
        if (trim($scheduledAt) === '') {
            return (new DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s');
        }

        $user = User::find($userId);
        $tzName = is_array($user) && !empty($user['timezone']) ? (string) $user['timezone'] : 'UTC';

        try {
            $userTz = new DateTimeZone($tzName);
        } catch (\Throwable) {
            $userTz = $utc;
        }

        try {
            $dt = new DateTimeImmutable($scheduledAt, $userTz);
        } catch (\Throwable) {
            Response::error('Invalid scheduled_at', 400);
        }

        return $dt->setTimezone($utc)->format('Y-m-d H:i:s');
    }
}
