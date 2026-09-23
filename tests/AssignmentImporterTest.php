<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Import\AssignmentImporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class AssignmentImporterTest extends TestCase
{
    public function testRealTestFilesCanBeImported(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($pdo);

        $importer = new AssignmentImporter($pdo);

        $result = $importer->import(
            __DIR__ . '/../data/Correspondance-affectation-groupes.xlsx',
            __DIR__ . '/../data/Donnees-test-anonym.xlsx'
        );

        self::assertSame(628, $result['students']);
        self::assertSame(8, $result['profiles']);
        self::assertSame(34, $result['groups']);

        self::assertSame(
            34,
            $result['profile_groups']
        );

        self::assertSame(
            628,
            (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn()
        );

        self::assertSame(
            628,
            (int) $pdo->query(
                'SELECT SUM(capacity) FROM groups'
            )->fetchColumn()
        );

        self::assertSame(
            16,
            (int) $pdo->query(
                "SELECT capacity FROM groups WHERE name = 'A8b'"
            )->fetchColumn()
        );

        $expectedCapacities = [
            'A10a' => 17,
            'A10b' => 17,
            'A1a' => 21,
            'A1b' => 21,
            'A2a' => 21,
            'A2b' => 21,
            'A3a' => 19,
            'A3b' => 14,
            'A3c' => 3,
            'A4a' => 18,
            'A4b' => 17,
            'A5a' => 16,
            'A5b' => 17,
            'A6a' => 16,
            'A6b' => 17,
            'A7a' => 15,
            'A7b' => 18,
            'A8a' => 18,
            'A8b' => 16,
            'A9a' => 16,
            'A9b' => 16,
            'B1a' => 2,
            'B1b' => 20,
            'B2a' => 17,
            'B2b' => 18,
            'B3a' => 19,
            'B3b' => 18,
            'B4a' => 17,
            'B4b' => 18,
            'Bind' => 17,
            'Santé 1' => 32,
            'Santé 2' => 32,
            'Santé 3' => 32,
            'Santé 4' => 32,
        ];

        $actualCapacities = $pdo
            ->query('SELECT name, capacity FROM groups ORDER BY name')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame($expectedCapacities, $actualCapacities);
    }

    public function testProfileGroupForeignKeysWork(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($pdo);

        $pdo->exec("INSERT INTO profiles (name) VALUES ('Test')");
        $pdo->exec("INSERT INTO groups (name, capacity) VALUES ('A4a', 0)");

        $profileId = (int) $pdo->query(
            "SELECT id FROM profiles WHERE name = 'Test'"
        )->fetchColumn();

        $groupId = (int) $pdo->query(
            "SELECT id FROM groups WHERE name = 'A4a'"
        )->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO profile_groups (profile_id, group_id)
         VALUES (:profile_id, :group_id)'
        );

        $stmt->execute([
            'profile_id' => $profileId,
            'group_id' => $groupId,
        ]);

        self::assertSame(1, (int) $pdo->query(
            'SELECT COUNT(*) FROM profile_groups'
        )->fetchColumn());
    }
}
