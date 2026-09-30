<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LBonnefond\TdSwap\Database\DatabaseFactory;

$pdo = DatabaseFactory::create(
    __DIR__ . '/../storage/td-swap.sqlite'
);

header('Content-Type: text/plain; charset=utf-8');

echo "TD-Swap\n";
echo "Database: OK\n";