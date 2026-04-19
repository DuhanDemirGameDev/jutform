<?php

namespace JutForm\Controllers;

use JutForm\Core\Database;
use JutForm\Core\RedisClient;
use JutForm\Core\Request;
use JutForm\Core\RequestContext;
use JutForm\Core\Response;
use JutForm\Models\User;

class AdminController
{
    public function revenue(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $user = \JutForm\Models\User::find($uid);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            Response::error('Forbidden', 403);
        }
        $pdo = Database::getInstance();
        $versionRow = $pdo->query('SELECT COALESCE(MAX(id), 0) AS v FROM payments')->fetch(\PDO::FETCH_ASSOC);
        $version = (string) ((int) ($versionRow['v'] ?? 0));
        $cacheKey = 'admin:revenue_total:v1:' . $version;
        $cached = $this->cacheGetFloat($cacheKey);
        if ($cached !== null) {
            Response::json(['revenue_total' => $cached]);
        }

        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM payments
             WHERE status = ?'
        );
        $stmt->execute(['approved']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $total = (float) ($row['total'] ?? 0);
        $this->cacheSetFloat($cacheKey, $total, 60);
        Response::json(['revenue_total' => $total]);
    }

    public function internalConfig(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $user = User::find($uid);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            Response::error('Forbidden', 403);
        }
        $pdo = Database::getInstance();
        $rows = $pdo->query('SELECT config_key, value FROM app_config ORDER BY id DESC LIMIT 50')
            ->fetchAll(\PDO::FETCH_ASSOC);
        Response::json(['items' => $rows]);
    }

    public function logs(Request $request): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $user = \JutForm\Models\User::find($uid);
        if (!$user || ($user['role'] ?? '') !== 'admin') {
            Response::error('Forbidden', 403);
        }
        $path = '/var/log/php/error.log';
        $lines = [];
        if (is_readable($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                $lines = array_slice(array_filter(explode("\n", $content)), -100);
            }
        }
        Response::html('<pre>' . htmlspecialchars(implode("\n", $lines)) . '</pre>');
    }

    private function cacheGetFloat(string $key): ?float
    {
        try {
            $redis = RedisClient::getInstance();
            $value = $redis->get($key);
            if (!is_string($value) || $value === '') {
                return null;
            }
            return (float) $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheSetFloat(string $key, float $value, int $ttl): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->setex($key, $ttl, (string) $value);
        } catch (\Throwable) {
            // Best-effort cache only.
        }
    }
}
