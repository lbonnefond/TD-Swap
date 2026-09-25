<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignSnapshotTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->insertReferenceData();
    }

    public function testCampaignSnapshotCanContainGroupsAndStudents(): void
    {
        $campaignId = $this->insertCampaign();

        $this->snapshotGroups($campaignId);
        $this->snapshotStudents($campaignId);

        self::assertSame(
            2,
            (int) $this->pdo
                ->query(
                    'SELECT COUNT(*)
                     FROM campaign_groups
                     WHERE campaign_id = ' . $campaignId
                )
                ->fetchColumn()
        );

        self::assertSame(
            2,
            (int) $this->pdo
                ->query(
                    'SELECT COUNT(*)
                     FROM campaign_students
                     WHERE campaign_id = ' . $campaignId
                )
                ->fetchColumn()
        );
    }

    public function testStudentInitialGroupUsesCampaignGroupId(): void
    {
        $campaignId = $this->insertCampaign();

        $this->snapshotGroups($campaignId);
        $this->snapshotStudents($campaignId);

        $row = $this->pdo
            ->query(
                'SELECT
                    cs.initial_group_id,
                    cg.id AS campaign_group_id,
                    cg.group_id AS source_group_id
                 FROM campaign_students cs
                 JOIN campaign_groups cg
                   ON cg.campaign_id = cs.campaign_id
                  AND cg.id = cs.initial_group_id
                 WHERE cs.campaign_id = ' . $campaignId . '
                   AND cs.student_number = \'100001\''
            )
            ->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame(1, (int) $row['source_group_id']);
        self::assertSame(
            (int) $row['campaign_group_id'],
            (int) $row['initial_group_id']
        );
    }

    public function testProfileGroupsCanBeSnapshottedUsingCampaignGroupIds(): void
    {
        $campaignId = $this->insertCampaign();

        $this->snapshotGroups($campaignId);

        $this->pdo->exec(
            'INSERT INTO campaign_profile_groups (
                campaign_id,
                profile_id,
                campaign_group_id
            )
            SELECT
                ' . $campaignId . ',
                pg.profile_id,
                cg.id
            FROM profile_groups pg
            JOIN campaign_groups cg
              ON cg.campaign_id = ' . $campaignId . '
             AND cg.group_id = pg.group_id'
        );

        $count = (int) $this->pdo
            ->query(
                'SELECT COUNT(*)
                 FROM campaign_profile_groups
                 WHERE campaign_id = ' . $campaignId
            )
            ->fetchColumn();

        self::assertSame(2, $count);
    }

    private function insertReferenceData(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, name) VALUES
                (1, 'Biologie')"
        );

        $this->pdo->exec(
            "INSERT INTO groups (id, name, capacity) VALUES
                (1, 'A1', 20),
                (2, 'A2', 20)"
        );

        $this->pdo->exec(
            'INSERT INTO profile_groups (profile_id, group_id)
             VALUES
                (1, 1),
                (1, 2)'
        );

        $this->pdo->exec(
            "INSERT INTO students (
                id,
                student_number,
                surname,
                first_name,
                profile_id,
                initial_group_id
            ) VALUES
                (1, '100001', 'Dupont', 'Jean', 1, 1),
                (2, '100002', 'Martin', 'Anne', 1, 2)"
        );
    }

    private function insertCampaign(): int
    {
        $this->pdo->exec(
            "INSERT INTO campaigns (
                name,
                starts_at,
                closes_at,
                created_at
            ) VALUES (
                'Test',
                '2026-10-01T08:00:00+02:00',
                '2026-10-10T18:00:00+02:00',
                '2026-09-24T10:00:00+02:00'
            )"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function snapshotGroups(int $campaignId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaign_groups (
                campaign_id,
                group_id,
                name,
                capacity
            )
            SELECT
                ?,
                id,
                name,
                capacity
            FROM groups'
        );

        $statement->execute([$campaignId]);
    }

    private function snapshotStudents(int $campaignId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaign_students (
                campaign_id,
                student_id,
                student_number,
                surname,
                first_name,
                profile_id,
                initial_group_id
            )
            SELECT
                ?,
                s.id,
                s.student_number,
                s.surname,
                s.first_name,
                s.profile_id,
                cg.id
            FROM students s
            JOIN campaign_groups cg
              ON cg.campaign_id = ?
             AND cg.group_id = s.initial_group_id'
        );

        $statement->execute([
            $campaignId,
            $campaignId,
        ]);
    }
}