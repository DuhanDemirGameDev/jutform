<?php

require_once __DIR__ . '/../vendor/autoload.php';

$pdo = \JutForm\Core\Database::getInstance();

$pdo->exec("
    ALTER TABLE forms
    ADD INDEX idx_user_updated_at (user_id, updated_at)
");

$pdo->exec("
    ALTER TABLE submissions
    ADD INDEX idx_form_submitted_at (form_id, submitted_at)
");
