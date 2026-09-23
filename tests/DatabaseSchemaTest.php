<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    public function testSchemaCanBeCreated(): void
    {
        $pdo = new PDO('sqlite::memory:');

        Schema::create($pdo);

        $tables = $pdo
            ->query(
                "SELECT name FROM sqlite_master
                 WHERE type = 'table'
                   AND name NOT LIKE 'sqlite_%'
                 ORDER BY name"
            )
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([
            'campaigns',
            'groups',
            'profile_groups',
            'profiles',
            'requests',
            'students',
        ], $tables);
    }

    public function testForeignKeysAreEnabled(): void
    {
        $pdo = new PDO('sqlite::memory:');

        Schema::create($pdo);

        self::assertSame(
            1,
            (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn()
        );
    }
}
