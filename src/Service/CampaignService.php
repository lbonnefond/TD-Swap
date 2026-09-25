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
    ) {
    }

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
}