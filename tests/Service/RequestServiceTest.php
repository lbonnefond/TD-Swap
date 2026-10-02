<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Service;

use DateTimeImmutable;
use DomainException;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Service\CampaignService;
use LBonnefond\TdSwap\Service\RequestService;
use PDO;
use PHPUnit\Framework\TestCase;

final class RequestServiceTest extends TestCase
{
    private PDO $pdo;
    private RequestService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $campaignRepository = new CampaignRepository($this->pdo);
        $campaignService = new CampaignService(
            $this->pdo,
            $campaignRepository,
        );

        $this->pdo->exec(
            "INSERT INTO profiles (id, name)
             VALUES (1, 'Biologie')"
        );

        $this->pdo->exec(
            "INSERT INTO groups (id, name, capacity)
             VALUES
                (1, 'A1', 20),
                (2, 'A2', 20),
                (3, 'A3', 20)"
        );

        $this->pdo->exec(
            "INSERT INTO profile_groups (profile_id, group_id)
             VALUES
                (1, 1),
                (1, 2),
                (1, 3)"
        );

        $this->pdo->exec(
            "INSERT INTO students
                (id, student_number, surname, first_name, profile_id, initial_group_id)
             VALUES
                (1, 'S001', 'Dupont', 'Jean', 1, 1)"
        );

        $campaign = $campaignService->createCampaign(
            new Campaign(
                null,
                'Test',
                new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
                new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            )
        );

        $campaignService->openCampaign(
            $campaign->id,
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
        );

        $this->service = new RequestService(
            $this->pdo,
            $campaignRepository,
            new CampaignRequestRepository($this->pdo),
        );
    }

    public function testSubmitCreatesRequest(): void
    {
        $now = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $request = $this->service->submit(
            1,
            1,
            [2, 3],
            $now,
        );

        self::assertNotNull($request->id);
        self::assertSame(1, $request->campaignId);
        self::assertSame(1, $request->campaignStudentId);
        self::assertSame([2, 3], $request->targetCampaignGroupIds);
        self::assertSame(
            $now->format(DATE_ATOM),
            $request->firstSubmittedAt->format(DATE_ATOM)
        );
    }

    public function testUpdatePreservesFirstSubmissionDate(): void
    {
        $first = new DateTimeImmutable('2026-10-02T10:00:00+02:00');
        $second = new DateTimeImmutable('2026-10-03T11:00:00+02:00');

        $created = $this->service->submit(1, 1, [2], $first);

        $updated = $this->service->update(1, 1, [3], $second);

        self::assertSame($created->id, $updated->id);
        self::assertSame([3], $updated->targetCampaignGroupIds);
        self::assertSame(
            $first->format(DATE_ATOM),
            $updated->firstSubmittedAt->format(DATE_ATOM)
        );
        self::assertSame(
            $second->format(DATE_ATOM),
            $updated->updatedAt->format(DATE_ATOM)
        );
    }

    public function testWithdrawMarksRequestAsWithdrawn(): void
    {
        $submitted = new DateTimeImmutable('2026-10-02T10:00:00+02:00');
        $withdrawn = new DateTimeImmutable('2026-10-03T11:00:00+02:00');

        $this->service->submit(1, 1, [2], $submitted);

        $request = $this->service->withdraw(1, 1, $withdrawn);

        self::assertNotNull($request->withdrawnAt);
        self::assertSame(
            $withdrawn->format(DATE_ATOM),
            $request->withdrawnAt->format(DATE_ATOM)
        );
    }

    public function testCurrentGroupCannotBeTarget(): void
    {
        $this->expectException(DomainException::class);

        $this->service->submit(
            1,
            1,
            [1],
            new DateTimeImmutable('2026-10-02T10:00:00+02:00'),
        );
    }

    public function testSecondRequestIsRejected(): void
    {
        $now = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $this->service->submit(1, 1, [2], $now);

        $this->expectException(DomainException::class);

        $this->service->submit(1, 1, [3], $now);
    }

    public function testRequestCannotBeSubmittedBeforeCampaignStart(): void
    {
        $this->expectException(DomainException::class);

        $this->service->submit(
            1,
            1,
            [2],
            new DateTimeImmutable('2026-09-30T10:00:00+02:00'),
        );
    }

    public function testRequestCannotBeSubmittedAfterCampaignClose(): void
    {
        $this->expectException(DomainException::class);

        $this->service->submit(
            1,
            1,
            [2],
            new DateTimeImmutable('2026-10-10T18:01:00+02:00'),
        );
    }

    public function testIneligibleTargetGroupIsRejected(): void
    {
        // A3 n'est pas autorisé pour le profil de l'étudiant.
        $this->pdo->exec(
            'DELETE FROM campaign_profile_groups
     WHERE campaign_id = 1
       AND profile_id = 1
       AND campaign_group_id = (
           SELECT id
           FROM campaign_groups
           WHERE campaign_id = 1
             AND group_id = 3
       )'
        );

        $this->expectException(DomainException::class);

        $this->service->submit(
            1,
            1,
            [3],
            new DateTimeImmutable('2026-10-02T10:00:00+02:00'),
        );
    }

    public function testWithdrawnRequestCannotBeWithdrawnAgain(): void
    {
        $submitted = new DateTimeImmutable('2026-10-02T10:00:00+02:00');
        $withdrawn = new DateTimeImmutable('2026-10-03T11:00:00+02:00');

        $this->service->submit(1, 1, [2], $submitted);
        $this->service->withdraw(1, 1, $withdrawn);

        $this->expectException(DomainException::class);

        $this->service->withdraw(1, 1, $withdrawn);
    }

    public function testRequestCanBeWithdrawn(): void
    {
        $submitted = new DateTimeImmutable('2026-10-02T10:00:00+02:00');
        $withdrawn = new DateTimeImmutable('2026-10-03T11:00:00+02:00');

        $this->service->submit(
            1,
            1,
            [2],
            $submitted
        );

        $request = $this->service->withdrawRequest(
            1,
            'S001',
            $withdrawn
        );

        self::assertNotNull($request->withdrawnAt);
        self::assertSame(
            $withdrawn->format(DATE_ATOM),
            $request->withdrawnAt->format(DATE_ATOM)
        );
    }
}
