<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LBonnefond\TdSwap\Database\DatabaseFactory;
use LBonnefond\TdSwap\Import\AssignmentImporter;

$pdo = DatabaseFactory::create(
    __DIR__ . '/../storage/td-swap.sqlite'
);

$importer = new AssignmentImporter($pdo);

$result = $importer->import(
    __DIR__ . '/../data/Correspondance-affectation-groupes.xlsx',
    __DIR__ . '/../data/Donnees-test-anonym.xlsx',
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
) . PHP_EOL;
