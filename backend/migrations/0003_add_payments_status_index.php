<?php

require_once __DIR__ . '/../vendor/autoload.php';

$pdo = \JutForm\Core\Database::getInstance();

$pdo->exec("
    ALTER TABLE payments
    ADD INDEX idx_status_paid_at (status, paid_at)
");
