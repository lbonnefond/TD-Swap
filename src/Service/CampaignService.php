<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Service;

use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use PDO;
use Throwable;

final class CampaignService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CampaignRepository $campaignRepository,
    ) {}

    public function createCampaign(Campaign $campaign): Campaign
    {
        $this->pdo->beginTransaction();

        try {
            $createdCampaign = $this->campaignRepository->create(
                $campaign
            );

            $this->snapshotGroups($createdCampaign->id);
            $this->snapshotStudents($createdCampaign->id);
            $this->snapshotProfileGroups($createdCampaign->id);

            $this->pdo->commit();

            return $createdCampaign;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
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

    private function snapshotProfileGroups(int $campaignId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaign_profile_groups (
                campaign_id,
                profile_id,
                campaign_group_id
            )
            SELECT
                ?,
                pg.profile_id,
                cg.id
            FROM profile_groups pg
            JOIN campaign_groups cg
              ON cg.campaign_id = ?
             AND cg.group_id = pg.group_id'
        );

        $statement->execute([
            $campaignId,
            $campaignId,
        ]);
    }

    public function openCampaign(
        int $campaignId,
        \DateTimeImmutable $now,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'draft') {
            throw new \DomainException(
                'Seule une campagne draft peut être ouverte.'
            );
        }

        if ($now < $campaign->startsAt) {
            throw new \DomainException(
                'La campagne ne peut pas être ouverte avant sa date de début.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'open',
        );
    }

    public function closeCampaign(
        int $campaignId,
        \DateTimeImmutable $now,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'open') {
            throw new \DomainException(
                'Seule une campagne open peut être fermée.'
            );
        }

        if ($now < $campaign->closesAt) {
            throw new \DomainException(
                'La campagne ne peut pas être fermée avant sa date de fin.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'closed',
        );
    }

    public function markAsMatched(
        int $campaignId,
        \DateTimeImmutable $now,
    ): Campaign {
        $campaign = $this->requireCampaign($campaignId);

        if ($campaign->status !== 'closed') {
            throw new \DomainException(
                'Seule une campagne closed peut être marquée comme matched.'
            );
        }

        return $this->campaignRepository->updateStatus(
            $campaignId,
            'matched',
            $now,
        );
    }

    private function requireCampaign(int $campaignId): Campaign
    {
        $campaign = $this->campaignRepository->findById($campaignId);

        if ($campaign === null) {
            throw new \RuntimeException(
                sprintf('Campagne introuvable : %d', $campaignId)
            );
        }

        return $campaign;
    }
}
