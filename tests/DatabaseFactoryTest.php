<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\DatabaseFactory;
use PHPUnit\Framework\TestCase;

final class DatabaseFactoryTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir()
            . '/td-swap-test-' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function testDatabaseFileIsCreatedWithSchema(): void
    {
        $pdo = DatabaseFactory::create($this->databasePath);

        self::assertFileExists($this->databasePath);

        $tables = $pdo
            ->query(
                "SELECT name FROM sqlite_master
                 WHERE type = 'table'
                   AND name NOT LIKE 'sqlite_%'
                 ORDER BY name"
            )
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame([
            'campaigns',
            'groups',
            'profile_groups',
            'profiles',
            'requests',
            'students',
        ], $tables);
    }
}
