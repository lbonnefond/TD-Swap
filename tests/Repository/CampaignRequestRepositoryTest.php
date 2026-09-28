<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Repository;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\CampaignRequest;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignRequestRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CampaignRequestRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->pdo->exec(
            "INSERT INTO campaigns
                (name, starts_at, closes_at, status, created_at)
             VALUES
                ('Test', '2026-10-01T08:00:00+02:00',
                 '2026-10-10T18:00:00+02:00', 'open',
                 '2026-09-01T10:00:00+02:00')"
        );

        $this->pdo->exec(
            "INSERT INTO profiles (id, name)
             VALUES (1, 'Biologie')"
        );

        $this->pdo->exec(
            "INSERT INTO groups (id, name, capacity)
             VALUES (1, 'A1', 20), (2, 'A2', 20), (3, 'A3', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_groups
                (campaign_id, group_id, name, capacity)
             VALUES
                (1, 1, 'A1', 20),
                (1, 2, 'A2', 20),
                (1, 3, 'A3', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO students
                (id, student_number, surname, first_name, profile_id, initial_group_id)
             VALUES
                (1, 'S001', 'Dupont', 'Jean', 1, 1)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_students
                (campaign_id, student_id, student_number, surname,
                 first_name, profile_id, initial_group_id)
             VALUES
                (1, 1, 'S001', 'Dupont', 'Jean', 1, 1)"
        );

        $this->repository = new CampaignRequestRepository($this->pdo);
    }

    public function testCreateAndFindById(): void
    {
        $date = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $request = $this->repository->create(
            new CampaignRequest(
                null,
                1,
                1,
                [2, 3],
                $date,
                $date,
            )
        );

        self::assertNotNull($request->id);
        self::assertSame(1, $request->campaignId);
        self::assertSame(1, $request->campaignStudentId);
        self::assertSame([2, 3], $request->targetCampaignGroupIds);

        $found = $this->repository->findById($request->id);

        self::assertNotNull($found);
        self::assertSame($request->id, $found->id);
        self::assertSame([2, 3], $found->targetCampaignGroupIds);
    }

    public function testFindByCampaignAndStudent(): void
    {
        $date = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $created = $this->repository->create(
            new CampaignRequest(null, 1, 1, [2], $date, $date)
        );

        $found = $this->repository->findByCampaignAndStudent(1, 1);

        self::assertNotNull($found);
        self::assertSame($created->id, $found->id);
    }

    public function testUpdatePreservesFirstSubmissionDate(): void
    {
        $first = new DateTimeImmutable('2026-10-02T10:00:00+02:00');
        $updated = new DateTimeImmutable('2026-10-03T11:00:00+02:00');

        $created = $this->repository->create(
            new CampaignRequest(null, 1, 1, [2], $first, $first)
        );

        $result = $this->repository->update(
            new CampaignRequest(
                $created->id,
                1,
                1,
                [3],
                $first,
                $updated,
            )
        );

        self::assertSame('[3]', json_encode($result->targetCampaignGroupIds));
        self::assertSame(
            $first->format(DATE_ATOM),
            $result->firstSubmittedAt->format(DATE_ATOM)
        );
        self::assertSame(
            $updated->format(DATE_ATOM),
            $result->updatedAt->format(DATE_ATOM)
        );
    }

    public function testFindActiveByCampaignExcludesWithdrawnRequests(): void
    {
        $date = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $this->pdo->exec(
            "INSERT INTO students
            (id, student_number, surname, first_name, profile_id, initial_group_id)
         VALUES
            (2, 'S002', 'Martin', 'Paul', 1, 2)"
        );

        $this->pdo->exec(
            "INSERT INTO campaign_students
            (campaign_id, student_id, student_number, surname,
             first_name, profile_id, initial_group_id)
         VALUES
            (1, 2, 'S002', 'Martin', 'Paul', 1, 2)"
        );

        $active = $this->repository->create(
            new CampaignRequest(null, 1, 1, [2], $date, $date)
        );

        $withdrawn = $this->repository->create(
            new CampaignRequest(
                null,
                1,
                2,
                [3],
                $date,
                $date,
                new DateTimeImmutable('2026-10-03T10:00:00+02:00'),
            )
        );

        $requests = $this->repository->findActiveByCampaign(1);

        self::assertCount(1, $requests);
        self::assertSame($active->id, $requests[0]->id);
        self::assertNotSame($withdrawn->id, $requests[0]->id);
    }
}
