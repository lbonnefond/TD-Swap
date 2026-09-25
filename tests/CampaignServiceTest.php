<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Service\CampaignService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignServiceTest extends TestCase
{
    private PDO $pdo;
    private CampaignService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->insertReferenceData();

        $this->service = new CampaignService(
            $this->pdo,
            new CampaignRepository($this->pdo),
        );
    }

    public function testCreateCampaignCreatesCompleteSnapshot(): void
    {
        $campaign = $this->service->createCampaign(
            new Campaign(
                null,
                'Échanges TD semestre 1',
                new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
                new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
                'draft',
                null,
                new DateTimeImmutable('2026-09-24T10:00:00+02:00'),
            )
        );

        self::assertNotNull($campaign->id);

        self::assertSame(
            2,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM campaign_groups')
                ->fetchColumn()
        );

        self::assertSame(
            2,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM campaign_students')
                ->fetchColumn()
        );

        self::assertSame(
            2,
            (int) $this->pdo
                ->query('SELECT COUNT(*) FROM campaign_profile_groups')
                ->fetchColumn()
        );
    }

    public function testSnapshotIsIndependentFromLaterReferenceChanges(): void
    {
        $campaign = $this->service->createCampaign(
            new Campaign(
                null,
                'Échanges TD semestre 1',
                new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
                new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            )
        );

        $group = $this->pdo->query(
            'SELECT name, capacity
             FROM campaign_groups
             WHERE campaign_id = ' . $campaign->id . '
               AND group_id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame('A1', $group['name']);
        self::assertSame(20, (int) $group['capacity']);

        $this->pdo->exec(
            "UPDATE groups
             SET name = 'A1-modifié', capacity = 99
             WHERE id = 1"
        );

        $group = $this->pdo->query(
            'SELECT name, capacity
             FROM campaign_groups
             WHERE campaign_id = ' . $campaign->id . '
               AND group_id = 1'
        )->fetch(PDO::FETCH_ASSOC);

        self::assertSame('A1', $group['name']);
        self::assertSame(20, (int) $group['capacity']);
    }

    private function insertReferenceData(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, name)
             VALUES (1, 'Biologie')"
        );

        $this->pdo->exec(
            "INSERT INTO groups (id, name, capacity)
             VALUES
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
}