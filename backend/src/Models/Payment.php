<?php

namespace JutForm\Models;

use JutForm\Core\Database;

class Payment
{
    public static function create(
        int $userId,
        string $amount,
        ?string $transactionId,
        string $status,
        string $gatewayHash,
        ?string $paidAt = null
    ): int {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO payments (user_id, amount, transaction_id, status, gateway_hash, paid_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $amount,
            $transactionId,
            $status,
            $gatewayHash,
            $paidAt ?? gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }
}
