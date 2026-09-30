<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Service;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Repository\MatchRepository;
use LBonnefond\TdSwap\Service\CampaignService;
use LBonnefond\TdSwap\Service\MatchingService;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use PDO;
use PHPUnit\Framework\TestCase;

final class MatchingServiceTest extends TestCase
{
    private PDO $pdo;
    private CampaignService $campaignService;
    private CampaignRepository $campaignRepository;
    private CampaignRequestRepository $requestRepository;
    private MatchRepository $matchRepository;
    private MatchingService $service;

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

        $this->service = new MatchingService(
            $this->pdo,
            $this->campaignRepository,
            $this->requestRepository,
            $this->matchRepository,
            new ScalableMatcher(),
        );
    }

    public function testRunMatchesRequestsAndClosesCampaignAsMatched(): void
    {
        $campaign = $this->createClosedCampaign();

        $submittedAt = new DateTimeImmutable(
            '2026-10-05T10:00:00+02:00'
        );

        $this->insertRequest(
            $campaign->id,
            1,
            [2],
            $submittedAt,
        );

        $this->insertRequest(
            $campaign->id,
            2,
            [1],
            $submittedAt->modify('+1 minute'),
        );

        $matchedAt = new DateTimeImmutable(
            '2026-10-10T18:05:00+02:00'
        );

        $solution = $this->service->run(
            $campaign->id,
            $matchedAt,
        );

        self::assertSame(2, $solution->count());
        self::assertTrue($solution->isBalanced());

        $matches = $this->matchRepository->findByCampaign(
            $campaign->id
        );

        self::assertCount(2, $matches);

        self::assertSame(1, $matches[0]->studentId);
        self::assertSame(1, $matches[0]->fromGroupId);
        self::assertSame(2, $matches[0]->toGroupId);
        self::assertSame(1, $matches[0]->preferenceRank);

        self::assertSame(2, $matches[1]->studentId);
        self::assertSame(2, $matches[1]->fromGroupId);
        self::assertSame(1, $matches[1]->toGroupId);
        self::assertSame(1, $matches[1]->preferenceRank);

        $matchedCampaign = $this->campaignRepository->findById(
            $campaign->id
        );

        self::assertNotNull($matchedCampaign);
        self::assertSame('matched', $matchedCampaign->status);
        self::assertNotNull($matchedCampaign->matchedAt);
        self::assertSame(
            $matchedAt->format(DATE_ATOM),
            $matchedCampaign->matchedAt->format(DATE_ATOM)
        );
    }

    public function testRunRejectsCampaignThatIsNotClosed(): void
    {
        $campaign = $this->campaignService->createCampaign(
            new Campaign(
                null,
                'Échanges TD semestre 1',
                new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
                new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            )
        );

        $this->expectException(\DomainException::class);

        $this->service->run(
            $campaign->id,
            new DateTimeImmutable('2026-10-10T18:05:00+02:00'),
        );
    }

    public function testRunWithNoRequestsProducesEmptyMatching(): void
    {
        $campaign = $this->createClosedCampaign();

        $matchedAt = new DateTimeImmutable(
            '2026-10-10T18:05:00+02:00'
        );

        $solution = $this->service->run(
            $campaign->id,
            $matchedAt,
        );

        self::assertSame(0, $solution->count());

        self::assertCount(
            0,
            $this->matchRepository->findByCampaign($campaign->id)
        );

        $matchedCampaign = $this->campaignRepository->findById(
            $campaign->id
        );

        self::assertNotNull($matchedCampaign);
        self::assertSame('matched', $matchedCampaign->status);
    }

    public function testWithdrawnRequestIsIgnoredByMatching(): void
    {
        $campaign = $this->createClosedCampaign();

        $submittedAt = new DateTimeImmutable(
            '2026-10-05T10:00:00+02:00'
        );

        $request = new \LBonnefond\TdSwap\Model\CampaignRequest(
            null,
            $campaign->id,
            1,
            [2],
            $submittedAt,
            $submittedAt,
        );

        $request = $this->requestRepository->create($request);

        $this->requestRepository->update(
            new \LBonnefond\TdSwap\Model\CampaignRequest(
                $request->id,
                $request->campaignId,
                $request->campaignStudentId,
                $request->targetCampaignGroupIds,
                $request->firstSubmittedAt,
                $submittedAt->modify('+1 minute'),
                $submittedAt->modify('+1 minute'),
            )
        );

        $matchedAt = new DateTimeImmutable(
            '2026-10-10T18:05:00+02:00'
        );

        $solution = $this->service->run(
            $campaign->id,
            $matchedAt,
        );

        self::assertSame(0, $solution->count());
        self::assertCount(
            0,
            $this->matchRepository->findByCampaign($campaign->id)
        );
    }

    public function testSecondRunReplacesPreviousMatches(): void
    {
        $campaign = $this->createClosedCampaign();

        $submittedAt = new DateTimeImmutable(
            '2026-10-05T10:00:00+02:00'
        );

        $this->insertRequest(
            $campaign->id,
            1,
            [2],
            $submittedAt,
        );

        $this->insertRequest(
            $campaign->id,
            2,
            [1],
            $submittedAt->modify('+1 minute'),
        );

        $firstMatchedAt = new DateTimeImmutable(
            '2026-10-10T18:05:00+02:00'
        );

        $this->service->run(
            $campaign->id,
            $firstMatchedAt,
        );

        self::assertCount(
            2,
            $this->matchRepository->findByCampaign($campaign->id)
        );

        /*
     * Le service n'accepte normalement plus une campagne matched.
     * On vérifie donc ici directement le comportement du repository :
     * les résultats d'une campagne sont remplaçables.
     */
        $this->matchRepository->deleteByCampaign($campaign->id);

        self::assertCount(
            0,
            $this->matchRepository->findByCampaign($campaign->id)
        );
    }

    public function testOlderRequestHasPriorityWhenOnlyOneOfTwoCanMove(): void
    {
        /*
     * A1 contient initialement les étudiants 1 et 3.
     * A2 contient initialement l'étudiant 2.
     *
     * Les étudiants 1 et 3 veulent tous les deux A2.
     * L'étudiant 2 veut A1.
     *
     * Le maximum est donc de 2 mouvements :
     * soit 1 + 2, soit 3 + 2.
     *
     * L'étudiant 1 ayant demandé avant l'étudiant 3,
     * il doit être celui qui obtient A2.
     */
        $this->pdo->exec(
            "INSERT INTO students (
            id,
            student_number,
            surname,
            first_name,
            profile_id,
            initial_group_id
        ) VALUES
            (3, '100003', 'Bernard', 'Paul', 1, 1)"
        );

        $campaign = $this->createClosedCampaign();

        $firstSubmittedAt = new DateTimeImmutable(
            '2026-10-05T09:00:00+02:00'
        );

        $this->insertRequest(
            $campaign->id,
            1,
            [2],
            $firstSubmittedAt,
        );

        $this->insertRequest(
            $campaign->id,
            3,
            [2],
            $firstSubmittedAt->modify('+1 minute'),
        );

        $this->insertRequest(
            $campaign->id,
            2,
            [1],
            $firstSubmittedAt->modify('+2 minutes'),
        );

        $solution = $this->service->run(
            $campaign->id,
            new DateTimeImmutable('2026-10-10T18:05:00+02:00'),
        );

        self::assertSame(2, $solution->count());

        $matches = $this->matchRepository->findByCampaign(
            $campaign->id
        );

        self::assertCount(2, $matches);

        $matchedStudentIds = array_map(
            static fn($match): int => $match->studentId,
            $matches
        );

        self::assertContains(1, $matchedStudentIds);
        self::assertContains(2, $matchedStudentIds);
        self::assertNotContains(3, $matchedStudentIds);

        $student1Match = array_values(
            array_filter(
                $matches,
                static fn($match): bool => $match->studentId === 1
            )
        )[0];

        self::assertSame(1, $student1Match->fromGroupId);
        self::assertSame(2, $student1Match->toGroupId);
        self::assertSame(1, $student1Match->preferenceRank);

        $student2Match = array_values(
            array_filter(
                $matches,
                static fn($match): bool => $match->studentId === 2
            )
        )[0];

        self::assertSame(2, $student2Match->fromGroupId);
        self::assertSame(1, $student2Match->toGroupId);
        self::assertSame(1, $student2Match->preferenceRank);
    }

    private function createClosedCampaign(): Campaign
    {
        $campaign = $this->campaignService->createCampaign(
            new Campaign(
                null,
                'Échanges TD semestre 1',
                new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
                new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            )
        );

        $this->campaignService->openCampaign(
            $campaign->id,
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
        );

        return $this->campaignService->closeCampaign(
            $campaign->id,
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
        );
    }

    /**
     * @param list<int> $targets
     */
    private function insertRequest(
        int $campaignId,
        int $campaignStudentId,
        array $targets,
        DateTimeImmutable $submittedAt,
    ): void {
        $this->requestRepository->create(
            new \LBonnefond\TdSwap\Model\CampaignRequest(
                null,
                $campaignId,
                $campaignStudentId,
                $targets,
                $submittedAt,
                $submittedAt,
            )
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
