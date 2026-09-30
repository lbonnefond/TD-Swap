<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Repository;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Move;
use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Repository\MatchRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class MatchRepositoryTest extends TestCase
{
    private PDO $pdo;
    private MatchRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->pdo->exec(
            "INSERT INTO campaigns (
                id, name, starts_at, closes_at, status, created_at
            ) VALUES (
                1, 'Campagne test',
                '2026-10-01T08:00:00+02:00',
                '2026-10-10T18:00:00+02:00',
                'closed',
                '2026-09-01T10:00:00+02:00'
            )"
        );

        $this->pdo->exec(
            "INSERT INTO groups (id, name, capacity) VALUES
                (1, 'A1', 20),
                (2, 'A2', 20),
                (3, 'A3', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO profiles (id, name)
             VALUES (1, 'Biologie')"
        );

        $this->pdo->exec(
            "INSERT INTO students (
                id, student_number, surname, first_name,
                profile_id, initial_group_id
            ) VALUES
                (1, 'S001', 'Dupont', 'Jean', 1, 1),
                (2, 'S002', 'Martin', 'Claire', 1, 2)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_groups (
                id, campaign_id, group_id, name, capacity
            ) VALUES
                (1, 1, 1, 'A1', 20),
                (2, 1, 2, 'A2', 20),
                (3, 1, 3, 'A3', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_students (
                id, campaign_id, student_id, student_number,
                surname, first_name, profile_id, initial_group_id
            ) VALUES
                (1, 1, 1, 'S001', 'Dupont', 'Jean', 1, 1),
                (2, 1, 2, 'S002', 'Martin', 'Claire', 1, 2)"
        );

        $this->repository = new MatchRepository($this->pdo);
    }

    public function testSaveAllPersistsMatchingSolution(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-11T10:00:00+02:00');

        $solution = new MatchingSolution([
            new Move(
                1,
                1,
                2,
                1,
                new DateTimeImmutable('2026-10-02T10:00:00+02:00')
            ),
            new Move(
                2,
                2,
                1,
                2,
                new DateTimeImmutable('2026-10-02T11:00:00+02:00')
            ),
        ]);

        $this->repository->saveAll(1, $solution, $createdAt);

        $results = $this->repository->findByCampaign(1);

        self::assertCount(2, $results);

        self::assertSame(1, $results[0]->campaignId);
        self::assertSame(1, $results[0]->studentId);
        self::assertSame(1, $results[0]->fromGroupId);
        self::assertSame(2, $results[0]->toGroupId);
        self::assertSame(1, $results[0]->preferenceRank);
        self::assertSame(
            $createdAt->format(DATE_ATOM),
            $results[0]->createdAt->format(DATE_ATOM)
        );

        self::assertSame(2, $results[1]->studentId);
        self::assertSame(2, $results[1]->fromGroupId);
        self::assertSame(1, $results[1]->toGroupId);
        self::assertSame(2, $results[1]->preferenceRank);
    }

    public function testFindByCampaignReturnsEmptyListWhenThereAreNoMatches(): void
    {
        self::assertSame([], $this->repository->findByCampaign(1));
    }

    public function testDeleteByCampaignRemovesOnlyThatCampaign(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-11T10:00:00+02:00');

        $solution = new MatchingSolution([
            new Move(
                1,
                1,
                2,
                1,
                new DateTimeImmutable('2026-10-02T10:00:00+02:00')
            ),
        ]);

        $this->repository->saveAll(1, $solution, $createdAt);

        // Deuxième campagne avec ses propres snapshots.
        $this->pdo->exec(
            "INSERT INTO campaigns (
                id, name, starts_at, closes_at, status, created_at
            ) VALUES (
                2, 'Deuxième campagne',
                '2027-10-01T08:00:00+02:00',
                '2027-10-10T18:00:00+02:00',
                'closed',
                '2027-09-01T10:00:00+02:00'
            )"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_groups (
                id, campaign_id, group_id, name, capacity
            ) VALUES
                (4, 2, 1, 'A1', 20),
                (5, 2, 2, 'A2', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_students (
                id, campaign_id, student_id, student_number,
                surname, first_name, profile_id, initial_group_id
            ) VALUES
                (3, 2, 1, 'S001', 'Dupont', 'Jean', 1, 4)"
        );

        $solution2 = new MatchingSolution([
            new Move(
                3,
                4,
                5,
                1,
                new DateTimeImmutable('2027-10-02T10:00:00+02:00')
            ),
        ]);

        $this->repository->saveAll(2, $solution2, $createdAt);

        $this->repository->deleteByCampaign(1);

        self::assertSame([], $this->repository->findByCampaign(1));
        self::assertCount(1, $this->repository->findByCampaign(2));
    }
}