<?php

require_once __DIR__ . '/../vendor/autoload.php';

$pdo = \JutForm\Core\Database::getInstance();

$pdo->exec("
    ALTER TABLE forms
    ADD FULLTEXT INDEX ft_title_description (title, description)
");
