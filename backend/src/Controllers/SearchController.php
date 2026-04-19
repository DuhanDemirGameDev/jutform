<?php

namespace JutForm\Controllers;

use JutForm\Core\Database;
use JutForm\Core\RedisClient;
use JutForm\Core\Request;
use JutForm\Core\RequestContext;
use JutForm\Core\Response;
use PDO;

class SearchController
{
    /**
     * @var array<string, string>
     */
    private const ADVANCED_SEARCH_FIELDS = [
        'title' => 'title',
        'description' => 'description',
        'status' => 'status',
    ];

    public function search(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            Response::json(['results' => []]);
        }
        $pdo = Database::getInstance();
        $like = '%' . $term . '%';
        $stmt = $pdo->prepare(
            'SELECT id, config_key, value FROM app_config WHERE value LIKE ? LIMIT 200'
        );
        $stmt->execute([$like]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        Response::json(['results' => $rows]);
    }

    public function advancedSearch(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $field = $this->normalizeSearchField((string) $request->query('field', 'title'));
        if ($field === null) {
            Response::error('Invalid search field', 400);
        }
        $term = trim((string) $request->query('term', ''));
        if ($term === '') {
            Response::json(['forms' => []]);
        }
        if (strlen($term) > 255) {
            Response::error('Search term too long', 400);
        }

        $cacheKey = $this->buildCacheKey((int) $uid, $field, $term);
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            Response::json(['forms' => $cached]);
        }

        $pdo = Database::getInstance();
        $sql = sprintf(
            'SELECT id, user_id, title, description, status, is_public, created_at, updated_at
             FROM forms
             WHERE user_id = :user_id
               AND %s LIKE :term ESCAPE \'\\\\\'
             ORDER BY updated_at DESC
             LIMIT 50',
            $field
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $uid,
            'term' => '%' . $this->escapeLike($term) . '%',
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->cacheSet($cacheKey, $rows, 60);
        Response::json(['forms' => $rows]);
    }

    private function normalizeSearchField(string $field): ?string
    {
        $field = strtolower(trim($field));
        return self::ADVANCED_SEARCH_FIELDS[$field] ?? null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function buildCacheKey(int $userId, string $field, string $term): string
    {
        return 'advanced_search:' . $userId . ':' . $field . ':' . sha1($term);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function cacheGet(string $key): ?array
    {
        try {
            $redis = RedisClient::getInstance();
            $payload = $redis->get($key);
            if (!is_string($payload) || $payload === '') {
                return null;
            }
            $decoded = json_decode($payload, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function cacheSet(string $key, array $rows, int $ttl): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex($key, $ttl, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Cache is an optimization only; ignore failures and continue serving data.
        }
    }
}
