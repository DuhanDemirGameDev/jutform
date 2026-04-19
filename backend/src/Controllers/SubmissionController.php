<?php

namespace JutForm\Controllers;

use JutForm\Core\Database;
use JutForm\Core\QueueService;
use JutForm\Core\RedisClient;
use JutForm\Core\Request;
use JutForm\Core\RequestContext;
use JutForm\Core\Response;
use JutForm\Models\Form;
use JutForm\Models\KeyValueStore;
use JutForm\Models\Submission;

class SubmissionController
{
    private const SNAPSHOT_TTL_SECONDS = 300;

    public function index(Request $request, string $id): void
    {
        $sessionUserId = RequestContext::$currentUserId;
        if ($sessionUserId === null) {
            Response::error('Unauthorized', 401);
        }
        $form = Form::find((int) $id);
        if (!$form) {
            Response::error('Not found', 404);
        }
        if ((int) $form['user_id'] !== $sessionUserId) {
            $shared = KeyValueStore::get((int) $id, 'shared_with_user_ids');
            $ids = $shared ? json_decode($shared, true) : [];
            if (!is_array($ids) || !in_array($sessionUserId, $ids, true)) {
                Response::error('Forbidden', 403);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 20)));
        $offset = ($page - 1) * $limit;

        $formId = (int) $id;
        $snapshot = $this->resolvePaginationSnapshot($sessionUserId, $formId, $page);
        $rows = Submission::findByForm(
            $formId,
            $limit,
            $offset,
            $snapshot['submitted_at'],
            $snapshot['id']
        );

        // Sidebar: the viewer's most recently updated forms, surfaced next to
        // the submissions table so they can jump between forms without going
        // back to the dashboard.
        $pdo = Database::getInstance();
        $related = [];
        if ($sessionUserId !== null) {
            $stmt = $pdo->prepare('SELECT id, title FROM forms WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20');
            $stmt->execute([$sessionUserId]);
            $related = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        Response::json([
            'submissions' => $rows,
            'page' => $page,
            'limit' => $limit,
            'snapshot' => [
                'id' => $snapshot['id'],
                'submitted_at' => $snapshot['submitted_at'],
            ],
            'related_forms' => $related,
        ]);
    }

    public function create(Request $request, string $id): void
    {
        $form = Form::find((int) $id);
        if (!$form) {
            Response::error('Not found', 404);
        }
        $body = $request->jsonBody();
        $dataJson = isset($body['data']) ? json_encode($body['data'], JSON_UNESCAPED_UNICODE) : '{}';
        $sid = Submission::create((int) $id, $dataJson, $request->ip());
        if (isset($form['user_id'])) {
            \JutForm\Models\Form::touchDashboardCache((int) $form['user_id']);
        }
        QueueService::dispatch('submission_notify', [
            'form_id' => (int) $id,
            'submission_id' => $sid,
        ]);
        Response::json(['id' => $sid], 201);
    }

    public function exportCsv(Request $request, string $id): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $form = Form::find((int) $id);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }
        $rows = Submission::findByForm((int) $id, 5000, 0);
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['id', 'submitted_at', 'data_json']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['id'], $r['submitted_at'], $r['data_json']]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        Response::csv('form-' . $id . '-submissions.csv', $csv);
    }

    /**
     * @return array{id:int|null, submitted_at:string|null}
     */
    private function resolvePaginationSnapshot(int $userId, int $formId, int $page): array
    {
        if ($page <= 1) {
            $snapshot = $this->freshSnapshot($formId);
            $this->storePaginationSnapshot($userId, $formId, $snapshot);
            return $snapshot;
        }

        $cached = $this->loadPaginationSnapshot($userId, $formId);
        if ($cached !== null) {
            return $cached;
        }

        $snapshot = $this->freshSnapshot($formId);
        $this->storePaginationSnapshot($userId, $formId, $snapshot);
        return $snapshot;
    }

    /**
     * @return array{id:int|null, submitted_at:string|null}
     */
    private function freshSnapshot(int $formId): array
    {
        $latest = Submission::latestForForm($formId);
        if ($latest === null) {
            return [
                'id' => null,
                'submitted_at' => null,
            ];
        }

        return $latest;
    }

    /**
     * @return array{id:int|null, submitted_at:string|null}|null
     */
    private function loadPaginationSnapshot(int $userId, int $formId): ?array
    {
        try {
            $redis = RedisClient::getInstance();
            $payload = $redis->get($this->snapshotCacheKey($userId, $formId));
            if (!is_string($payload) || $payload === '') {
                return null;
            }

            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                return null;
            }

            $submittedAt = $decoded['submitted_at'] ?? null;
            $id = $decoded['id'] ?? null;

            if ($submittedAt === null || $id === null) {
                return [
                    'id' => null,
                    'submitted_at' => null,
                ];
            }

            if (!is_string($submittedAt) || !is_int($id)) {
                return null;
            }

            return [
                'id' => $id,
                'submitted_at' => $submittedAt,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{id:int|null, submitted_at:string|null} $snapshot
     */
    private function storePaginationSnapshot(int $userId, int $formId, array $snapshot): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex(
                $this->snapshotCacheKey($userId, $formId),
                self::SNAPSHOT_TTL_SECONDS,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (\Throwable) {
            // Snapshot consistency is best-effort; continue without cache if Redis is unavailable.
        }
    }

    private function snapshotCacheKey(int $userId, int $formId): string
    {
        return 'submissions:snapshot:' . $userId . ':' . $formId;
    }
}
