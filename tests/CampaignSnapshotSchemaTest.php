<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Database\Schema;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class CampaignSnapshotSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);
    }

    public function testSameStudentCanBeSnapshottedInTwoCampaigns(): void
    {
        $this->insertReferenceData();

        $campaign1 = $this->insertCampaign('Campagne 1');
        $campaign2 = $this->insertCampaign('Campagne 2');

        $group1 = $this->insertCampaignGroup($campaign1, 1, 'A1', 20);
        $group2 = $this->insertCampaignGroup($campaign2, 1, 'A1', 20);

        $student1 = $this->insertCampaignStudent(
            $campaign1,
            1,
            1,
            $group1
        );

        $student2 = $this->insertCampaignStudent(
            $campaign2,
            1,
            1,
            $group2
        );

        self::assertNotSame($student1, $student2);

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM campaign_students')
            ->fetchColumn();

        self::assertSame(2, $count);
    }

    public function testSameGroupCanBeSnapshottedInTwoCampaigns(): void
    {
        $this->insertReferenceData();

        $campaign1 = $this->insertCampaign('Campagne 1');
        $campaign2 = $this->insertCampaign('Campagne 2');

        $group1 = $this->insertCampaignGroup($campaign1, 1, 'A1', 20);
        $group2 = $this->insertCampaignGroup($campaign2, 1, 'A1', 20);

        self::assertNotSame($group1, $group2);

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM campaign_groups')
            ->fetchColumn();

        self::assertSame(2, $count);
    }

    public function testRequestCannotReferenceStudentFromAnotherCampaign(): void
    {
        $this->insertReferenceData();

        $campaign1 = $this->insertCampaign('Campagne 1');
        $campaign2 = $this->insertCampaign('Campagne 2');

        $group1 = $this->insertCampaignGroup($campaign1, 1, 'A1', 20);
        $group2 = $this->insertCampaignGroup($campaign2, 1, 'A1', 20);

        $student1 = $this->insertCampaignStudent(
            $campaign1,
            1,
            1,
            $group1
        );

        $this->insertCampaignStudent(
            $campaign2,
            1,
            1,
            $group2
        );

        $this->expectException(PDOException::class);

        $this->pdo->prepare(
            'INSERT INTO requests (
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                target_1_campaign_group_id
            ) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $campaign2,
            $student1,
            '2026-09-24T10:00:00+02:00',
            '2026-09-24T10:00:00+02:00',
            $group2,
        ]);
    }

    public function testRequestCannotTargetGroupFromAnotherCampaign(): void
    {
        $this->insertReferenceData();

        $campaign1 = $this->insertCampaign('Campagne 1');
        $campaign2 = $this->insertCampaign('Campagne 2');

        $group1 = $this->insertCampaignGroup($campaign1, 1, 'A1', 20);
        $group2 = $this->insertCampaignGroup($campaign2, 1, 'A1', 20);

        $student1 = $this->insertCampaignStudent(
            $campaign1,
            1,
            1,
            $group1
        );

        $this->expectException(PDOException::class);

        $this->pdo->prepare(
            'INSERT INTO requests (
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                target_1_campaign_group_id
            ) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $campaign1,
            $student1,
            '2026-09-24T10:00:00+02:00',
            '2026-09-24T10:00:00+02:00',
            $group2,
        ]);
    }

    public function testDeletingCampaignDeletesItsSnapshotAndRequests(): void
    {
        $this->insertReferenceData();

        $campaign = $this->insertCampaign('Campagne 1');

        $group = $this->insertCampaignGroup(
            $campaign,
            1,
            'A1',
            20
        );

        $student = $this->insertCampaignStudent(
            $campaign,
            1,
            1,
            $group
        );

        $this->pdo->prepare(
            'INSERT INTO requests (
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                target_1_campaign_group_id
            ) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $campaign,
            $student,
            '2026-09-24T10:00:00+02:00',
            '2026-09-24T10:00:00+02:00',
            $group,
        ]);

        $this->pdo
            ->prepare('DELETE FROM campaigns WHERE id = ?')
            ->execute([$campaign]);

        self::assertSame(
            0,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM campaign_groups')
                ->fetchColumn()
        );

        self::assertSame(
            0,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM campaign_students')
                ->fetchColumn()
        );

        self::assertSame(
            0,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM requests')
                ->fetchColumn()
        );
    }

    private function insertReferenceData(): void
    {
        $this->pdo
            ->exec("INSERT INTO profiles (id, name) VALUES (1, 'Biologie')");

        $this->pdo
            ->exec("INSERT INTO groups (id, name, capacity) VALUES (1, 'A1', 20)");

        $this->pdo->exec(
            'INSERT INTO profile_groups (profile_id, group_id)
             VALUES (1, 1)'
        );

        $this->pdo->exec(
            "INSERT INTO students (
                id,
                student_number,
                surname,
                first_name,
                profile_id,
                initial_group_id
             ) VALUES (
                1,
                '100001',
                'Dupont',
                'Jean',
                1,
                1
             )"
        );
    }

    private function insertCampaign(string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaigns (
                name,
                starts_at,
                closes_at,
                created_at
            ) VALUES (?, ?, ?, ?)'
        );

        $statement->execute([
            $name,
            '2026-09-24T08:00:00+02:00',
            '2026-10-01T18:00:00+02:00',
            '2026-09-24T07:00:00+02:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertCampaignGroup(
        int $campaignId,
        int $groupId,
        string $name,
        int $capacity
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaign_groups (
                campaign_id,
                group_id,
                name,
                capacity
            ) VALUES (?, ?, ?, ?)'
        );

        $statement->execute([
            $campaignId,
            $groupId,
            $name,
            $capacity,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertCampaignStudent(
        int $campaignId,
        int $studentId,
        int $profileId,
        int $initialGroupId
    ): int {
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
                id,
                student_number,
                surname,
                first_name,
                ?,
                ?
            FROM students
            WHERE id = ?'
        );

        $statement->execute([
            $campaignId,
            $profileId,
            $initialGroupId,
            $studentId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}