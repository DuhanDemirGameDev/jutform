<?php

namespace JutForm\Models;

use JutForm\Core\Database;
use JutForm\Core\RedisClient;
use PDO;

class Form
{
    public static function find(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM forms WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM forms WHERE user_id = ? ORDER BY updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Returns the dashboard payload for a user's forms.
     *
     * This is optimized as a single aggregate query and cached briefly in Redis
     * to avoid repeated N+1 work during peak traffic.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findDashboardByUser(int $userId, int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $cacheKey = self::dashboardCacheKey($userId, $limit, $offset);
        $cached = self::cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sql = <<<'SQL'
            SELECT
                f.id,
                f.user_id,
                f.title,
                f.status,
                f.updated_at,
                COALESCE(s.submission_count, 0) AS submission_count,
                s.last_submission_at
            FROM forms f
            LEFT JOIN (
                SELECT s.form_id, COUNT(*) AS submission_count, MAX(s.submitted_at) AS last_submission_at
                FROM submissions s
                INNER JOIN forms f2 ON f2.id = s.form_id
                WHERE f2.user_id = ?
                GROUP BY s.form_id
            ) s ON s.form_id = f.id
            WHERE f.user_id = ?
            ORDER BY f.updated_at DESC
            LIMIT ?
            OFFSET ?
        SQL;

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute([$userId, $userId, $limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::cacheSet($cacheKey, $rows, 30);
        return $rows;
    }

    public static function countByUser(int $userId): int
    {
        $cacheKey = self::dashboardCountCacheKey($userId);
        try {
            $redis = RedisClient::getInstance();
            $cached = $redis->get($cacheKey);
            if (is_string($cached) && ctype_digit($cached)) {
                return (int) $cached;
            }
        } catch (\Throwable) {
        }

        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM forms WHERE user_id = ?');
        $stmt->execute([$userId]);
        $count = (int) $stmt->fetchColumn();

        try {
            $redis = RedisClient::getInstance();
            $redis->setex($cacheKey, 30, (string) $count);
        } catch (\Throwable) {
            // Cache is an optimization only.
        }

        return $count;
    }

    /**
     * Searches the authenticated user's forms using a cached, indexed path.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchByUser(int $userId, string $term, int $limit = 50): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $cacheKey = self::searchCacheKey($userId, $term, $limit);
        $cached = self::cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = self::runSearchQuery($userId, $term, $limit);
        self::cacheSet($cacheKey, $rows, 60);
        return $rows;
    }

    public static function create(array $data): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO forms (user_id, title, description, status, is_public, fields_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = $data['created_at'] ?? date('Y-m-d H:i:s');
        $stmt->execute([
            $data['user_id'],
            $data['title'],
            $data['description'] ?? null,
            $data['status'] ?? 'draft',
            $data['is_public'] ?? 0,
            $data['fields_json'] ?? '[]',
            $now,
            $now,
        ]);
        self::touchSearchCache((int) $data['user_id']);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $existing = self::find($id);
        if ($existing === null) {
            return;
        }
        $fields = [];
        $params = [];
        foreach (['title', 'description', 'status', 'is_public', 'fields_json'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "$k = ?";
                $params[] = $data[$k];
            }
        }
        if ($fields === []) {
            return;
        }
        $sql = 'UPDATE forms SET ' . implode(', ', $fields) . ', updated_at = ? WHERE id = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        self::touchSearchCache((int) $existing['user_id']);
    }

    public static function touchDashboardCache(int $userId): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex(self::dashboardVersionKey($userId), 86400, (string) microtime(true));
        } catch (\Throwable) {
            // Cache invalidation is best-effort.
        }
    }

    public static function touchSearchCache(int $userId): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex(self::searchVersionKey($userId), 86400, (string) microtime(true));
        } catch (\Throwable) {
            // Cache invalidation is best-effort.
        }
    }

    private static function dashboardCacheKey(int $userId, int $limit, int $offset): string
    {
        return 'forms:dashboard:' . $userId . ':' . self::dashboardVersion($userId) . ':' . $limit . ':' . $offset;
    }

    private static function dashboardVersionKey(int $userId): string
    {
        return 'forms:dashboard:ver:' . $userId;
    }

    private static function dashboardCountCacheKey(int $userId): string
    {
        return 'forms:dashboard:count:' . $userId . ':' . self::dashboardVersion($userId);
    }

    private static function searchCacheKey(int $userId, string $term, int $limit): string
    {
        return 'forms:search:' . $userId . ':' . self::searchVersion($userId) . ':' . sha1(mb_strtolower($term, 'UTF-8') . ':' . $limit);
    }

    private static function searchVersionKey(int $userId): string
    {
        return 'forms:search:ver:' . $userId;
    }

    private static function dashboardVersion(int $userId): string
    {
        try {
            $redis = RedisClient::getInstance();
            $version = $redis->get(self::dashboardVersionKey($userId));
            if (is_string($version) && $version !== '') {
                return $version;
            }
        } catch (\Throwable) {
        }
        return '0';
    }

    private static function searchVersion(int $userId): string
    {
        try {
            $redis = RedisClient::getInstance();
            $version = $redis->get(self::searchVersionKey($userId));
            if (is_string($version) && $version !== '') {
                return $version;
            }
        } catch (\Throwable) {
        }
        return '0';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function runSearchQuery(int $userId, string $term, int $limit): array
    {
        $pdo = Database::getInstance();
        $fullTextQuery = self::fullTextQuery($term);

        if ($fullTextQuery !== null) {
            $sql = sprintf(
                'SELECT id, user_id, title, description, status, is_public, created_at, updated_at
                 FROM forms
                 WHERE user_id = :user_id
                   AND (
                     MATCH(title, description) AGAINST (:fulltext_filter IN BOOLEAN MODE)
                     OR status LIKE :status_term ESCAPE \'\\\\\'
                   )
                 ORDER BY
                   MATCH(title, description) AGAINST (:fulltext_order IN BOOLEAN MODE) DESC,
                   updated_at DESC
                 LIMIT %d',
                $limit
            );
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'fulltext_filter' => $fullTextQuery,
                'fulltext_order' => $fullTextQuery,
                'status_term' => '%' . self::escapeLike($term) . '%',
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = sprintf(
            'SELECT id, user_id, title, description, status, is_public, created_at, updated_at
             FROM forms
             WHERE user_id = :user_id
               AND (
                 title LIKE :term ESCAPE \'\\\\\'
                 OR description LIKE :term ESCAPE \'\\\\\'
                 OR status LIKE :term ESCAPE \'\\\\\'
               )
             ORDER BY updated_at DESC
             LIMIT %d',
            $limit
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'term' => '%' . self::escapeLike($term) . '%',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function fullTextQuery(string $term): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($term, 'UTF-8')) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static function (string $token): bool {
            return mb_strlen($token, 'UTF-8') >= 3;
        })));

        if ($tokens === []) {
            return null;
        }

        return implode(' ', array_map(static function (string $token): string {
            return '+' . $token . '*';
        }, $tokens));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private static function cacheGet(string $key): ?array
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
    private static function cacheSet(string $key, array $rows, int $ttl): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex($key, $ttl, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Cache is an optimization only.
        }
    }
}
