<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Database;

use LBonnefond\TdSwap\Database\DatabaseFactory;
use LBonnefond\TdSwap\Database\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkingDatabaseTest extends TestCase
{
    public function testCreateForCampaignOpensExistingFileWithSamePragmas(): void
    {
        $src = new PDO('sqlite::memory:');
        Schema::create($src);

        $file = sys_get_temp_dir() . '/tdswap-wd-' . uniqid() . '.db';
        $src->exec('VACUUM INTO ' . $src->quote($file));
        $src = null;

        $pdo = DatabaseFactory::createForCampaign($file);

        self::assertSame(
            'WAL',
            strtoupper((string) $pdo->query('PRAGMA journal_mode')->fetchColumn())
        );
        self::assertSame(
            '5000',
            (string) $pdo->query('PRAGMA busy_timeout')->fetchColumn()
        );

        $pdo = null;

        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testCreateForCampaignRejectsMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        DatabaseFactory::createForCampaign(
            sys_get_temp_dir() . '/tdswap-inexistant-' . uniqid() . '.db'
        );
    }
}