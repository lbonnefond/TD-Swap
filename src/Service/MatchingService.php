<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use DateTimeImmutable;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Model\Request;
use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use LBonnefond\TdSwap\Repository\CampaignRequestRepository;
use LBonnefond\TdSwap\Repository\MatchRepository;
use PDO;

final class MatchingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignRequestRepository $requestRepository,
        private readonly MatchRepository $matchRepository,
        private readonly ScalableMatcher $matcher,
    ) {
    }

    public function run(
        int $campaignId,
        DateTimeImmutable $now,
    ): MatchingSolution {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \RuntimeException(
                sprintf('Campagne introuvable : %d', $campaignId)
            );
        }

        if ($campaign->status !== 'closed') {
            throw new \DomainException(
                'Seule une campagne closed peut être soumise au matching.'
            );
        }

        $campaignRequests =
            $this->requestRepository->findActiveByCampaign($campaignId);

        $requests = [];

        foreach ($campaignRequests as $campaignRequest) {
            $studentStatement = $this->pdo->prepare(
                'SELECT initial_group_id
                 FROM campaign_students
                 WHERE campaign_id = ?
                   AND id = ?'
            );

            $studentStatement->execute([
                $campaignId,
                $campaignRequest->campaignStudentId,
            ]);

            $row = $studentStatement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new \RuntimeException(
                    sprintf(
                        'Étudiant de campagne introuvable : %d',
                        $campaignRequest->campaignStudentId
                    )
                );
            }

            $requests[] = new Request(
                $campaignRequest->id,
                $campaignRequest->campaignStudentId,
                (int) $row['initial_group_id'],
                $campaignRequest->targetCampaignGroupIds,
                $campaignRequest->firstSubmittedAt,
            );
        }

        $solution = $this->matcher->findBest($requests);

        $this->pdo->beginTransaction();

        try {
            $this->matchRepository->deleteByCampaign($campaignId);

            $this->matchRepository->saveAll(
                $campaignId,
                $solution,
                $now,
            );

            $this->campaignRepository->updateStatus(
                $campaignId,
                'matched',
                $now,
            );

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return $solution;
    }
}
