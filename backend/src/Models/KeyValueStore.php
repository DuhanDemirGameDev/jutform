<?php

namespace JutForm\Models;

use JutForm\Core\Database;
use PDO;

class KeyValueStore
{
    /**
     * @var array<string, bool>
     */
    private const BOOLEAN_KEYS = [
        'require_login' => true,
    ];

    public static function get(int $formId, string $key): ?string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT value FROM form_settings WHERE form_id = ? AND setting_key = ? LIMIT 1'
        );
        $stmt->execute([$formId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return self::normalizeValueForKey($key, (string) $row['value']);
    }

    public static function getBool(int $formId, string $key): bool
    {
        $v = self::get($formId, $key);
        return $v === 'true';
    }

    public static function set(int $formId, string $key, string $value): void
    {
        $pdo = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $value = self::normalizeValueForKey($key, $value);
        $stmt = $pdo->prepare(
            'INSERT INTO form_settings (form_id, setting_key, value, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$formId, $key, $value, $now]);
    }

    public static function allForForm(int $formId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT setting_key, value FROM form_settings WHERE form_id = ?'
        );
        $stmt->execute([$formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $out[$key] = self::normalizeValueForKey($key, (string) $row['value']);
        }
        return $out;
    }

    private static function normalizeValueForKey(string $key, string $value): string
    {
        if (!isset(self::BOOLEAN_KEYS[$key])) {
            return $value;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return 'true';
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return 'false';
        }

        return $value;
    }
}
