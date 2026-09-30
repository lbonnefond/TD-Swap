<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Service;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Repository\MatchRepository;
use LBonnefond\TdSwap\Service\CampaignService;
use LBonnefond\TdSwap\Service\MatchingService;
use LBonnefond\TdSwap\Service\RequestService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignWorkflowTest extends TestCase
{
    private PDO $pdo;
    private CampaignRepository $campaignRepository;
    private CampaignRequestRepository $requestRepository;
    private MatchRepository $matchRepository;
    private CampaignService $campaignService;
    private RequestService $requestService;
    private MatchingService $matchingService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->insertReferenceData();

        $this->campaignRepository = new CampaignRepository($this->pdo);
        $this->requestRepository = new CampaignRequestRepository($this->pdo);
        $this->matchRepository = new MatchRepository($this->pdo);

        $this->campaignService = new CampaignService(
            $this->pdo,
            $this->campaignRepository,
        );

        $this->requestService = new RequestService(
            $this->pdo,
            $this->campaignRepository,
            $this->requestRepository,
        );

        $this->matchingService = new MatchingService(
            $this->pdo,
            $this->campaignRepository,
            $this->requestRepository,
            $this->matchRepository,
            new ScalableMatcher(),
        );
    }

    public function testCompleteCampaignWorkflow(): void
    {
        $campaign = new Campaign(
            null,
            'Campagne de test',
            new DateTimeImmutable('2026-10-01 08:00:00'),
            new DateTimeImmutable('2026-10-10 18:00:00'),
            'draft',
            null,
            new DateTimeImmutable('2026-09-20 10:00:00'),
        );

        $campaign = $this->campaignService->createCampaign($campaign);

        self::assertNotNull($campaign->id);
        self::assertSame('draft', $campaign->status);

        $campaignId = $campaign->id;

        $campaign = $this->campaignService->openCampaign(
            $campaignId,
            new DateTimeImmutable('2026-10-01 08:00:00'),
        );

        self::assertSame('open', $campaign->status);

        $student1 = $this->getCampaignStudentId($campaignId, 1);
        $student2 = $this->getCampaignStudentId($campaignId, 2);

        $group1 = $this->getCampaignGroupId($campaignId, 1);
        $group2 = $this->getCampaignGroupId($campaignId, 2);

        $request1 = $this->requestService->submit(
            $campaignId,
            $student1,
            [$group2],
            new DateTimeImmutable('2026-10-02 09:00:00'),
        );

        $request2 = $this->requestService->submit(
            $campaignId,
            $student2,
            [$group1],
            new DateTimeImmutable('2026-10-02 09:01:00'),
        );

        self::assertSame([$group2], $request1->targetCampaignGroupIds);
        self::assertSame([$group1], $request2->targetCampaignGroupIds);

        $activeRequests =
            $this->requestRepository->findActiveByCampaign($campaignId);

        self::assertCount(2, $activeRequests);

        $campaign = $this->campaignService->closeCampaign(
            $campaignId,
            new DateTimeImmutable('2026-10-10 18:00:00'),
        );

        self::assertSame('closed', $campaign->status);

        $solution = $this->matchingService->run(
            $campaignId,
            new DateTimeImmutable('2026-10-10 18:01:00'),
        );

        self::assertCount(2, $solution->moves);
        self::assertTrue($solution->isBalanced());

        $matches = $this->matchRepository->findByCampaign($campaignId);

        self::assertCount(2, $matches);

        self::assertSame('matched', $this->campaignRepository
            ->findById($campaignId)
            ?->status);

        self::assertNotNull(
            $this->campaignRepository->findById($campaignId)?->matchedAt
        );

        self::assertSame(
            $student1,
            $matches[0]->studentId,
        );

        self::assertSame(
            $group1,
            $matches[0]->fromGroupId,
        );

        self::assertSame(
            $group2,
            $matches[0]->toGroupId,
        );

        self::assertSame(
            $student2,
            $matches[1]->studentId,
        );

        self::assertSame(
            $group2,
            $matches[1]->fromGroupId,
        );

        self::assertSame(
            $group1,
            $matches[1]->toGroupId,
        );
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
            "INSERT INTO profile_groups (profile_id, group_id)
             VALUES
                (1, 1),
                (1, 2)"
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

    private function getCampaignStudentId(
        int $campaignId,
        int $studentId,
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM campaign_students
             WHERE campaign_id = ?
               AND student_id = ?'
        );

        $statement->execute([
            $campaignId,
            $studentId,
        ]);

        $id = $statement->fetchColumn();

        self::assertNotFalse($id);

        return (int) $id;
    }

    private function getCampaignGroupId(
        int $campaignId,
        int $groupId,
    ): int {
        $statement = $this->pdo->prepare(
            'SELECT id
             FROM campaign_groups
             WHERE campaign_id = ?
               AND group_id = ?'
        );

        $statement->execute([
            $campaignId,
            $groupId,
        ]);

        $id = $statement->fetchColumn();

        self::assertNotFalse($id);

        return (int) $id;
    }
}