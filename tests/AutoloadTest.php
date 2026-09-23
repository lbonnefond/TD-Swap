<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testAutoloadWorks(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $database = new Database($pdo);

        self::assertSame($pdo, $database->connection());
    }
}
