<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Repository;

use DateTimeImmutable;
use LBonnefond\TdSwap\Model\CampaignRequest;
use PDO;

final class CampaignRequestRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function create(CampaignRequest $request): CampaignRequest
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO requests (
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                withdrawn_at,
                target_1_campaign_group_id,
                target_2_campaign_group_id,
                target_3_campaign_group_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $targets = $request->targetCampaignGroupIds;

        $statement->execute([
            $request->campaignId,
            $request->campaignStudentId,
            $request->firstSubmittedAt->format(DATE_ATOM),
            $request->updatedAt->format(DATE_ATOM),
            $request->withdrawnAt?->format(DATE_ATOM),
            $targets[0],
            $targets[1] ?? null,
            $targets[2] ?? null,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?CampaignRequest
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                withdrawn_at,
                target_1_campaign_group_id,
                target_2_campaign_group_id,
                target_3_campaign_group_id
             FROM requests
             WHERE id = ?'
        );

        $statement->execute([$id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByCampaignAndStudent(
        int $campaignId,
        int $campaignStudentId,
    ): ?CampaignRequest {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                withdrawn_at,
                target_1_campaign_group_id,
                target_2_campaign_group_id,
                target_3_campaign_group_id
             FROM requests
             WHERE campaign_id = ?
               AND campaign_student_id = ?'
        );

        $statement->execute([
            $campaignId,
            $campaignStudentId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function update(
        CampaignRequest $request,
    ): CampaignRequest {
        if ($request->id === null) {
            throw new \InvalidArgumentException(
                'Une demande existante doit avoir un identifiant.'
            );
        }

        $targets = $request->targetCampaignGroupIds;

        $statement = $this->pdo->prepare(
            'UPDATE requests
             SET updated_at = ?,
                 withdrawn_at = ?,
                 target_1_campaign_group_id = ?,
                 target_2_campaign_group_id = ?,
                 target_3_campaign_group_id = ?
             WHERE id = ?'
        );

        $statement->execute([
            $request->updatedAt->format(DATE_ATOM),
            $request->withdrawnAt?->format(DATE_ATOM),
            $targets[0],
            $targets[1] ?? null,
            $targets[2] ?? null,
            $request->id,
        ]);

        $updated = $this->findById($request->id);

        if ($updated === null) {
            throw new \RuntimeException(
                sprintf('Demande introuvable : %d', $request->id)
            );
        }

        return $updated;
    }

    /**
     * @return list<CampaignRequest>
     */
    public function findActiveByCampaign(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                campaign_student_id,
                first_submitted_at,
                updated_at,
                withdrawn_at,
                target_1_campaign_group_id,
                target_2_campaign_group_id,
                target_3_campaign_group_id
             FROM requests
             WHERE campaign_id = ?
               AND withdrawn_at IS NULL
             ORDER BY first_submitted_at ASC, id ASC'
        );

        $statement->execute([$campaignId]);

        return array_map(
            fn(array $row): CampaignRequest => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return list<CampaignRequest>
     */
    public function findByCampaign(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
            id,
            campaign_id,
            campaign_student_id,
            first_submitted_at,
            updated_at,
            withdrawn_at,
            target_1_campaign_group_id,
            target_2_campaign_group_id,
            target_3_campaign_group_id
         FROM requests
         WHERE campaign_id = ?
         ORDER BY first_submitted_at ASC, id ASC'
        );

        $statement->execute([$campaignId]);

        return array_map(
            fn(array $row): CampaignRequest => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function hydrate(array $row): CampaignRequest
    {
        $targets = [
            (int) $row['target_1_campaign_group_id'],
        ];

        if ($row['target_2_campaign_group_id'] !== null) {
            $targets[] = (int) $row['target_2_campaign_group_id'];
        }

        if ($row['target_3_campaign_group_id'] !== null) {
            $targets[] = (int) $row['target_3_campaign_group_id'];
        }

        return new CampaignRequest(
            (int) $row['id'],
            (int) $row['campaign_id'],
            (int) $row['campaign_student_id'],
            $targets,
            new DateTimeImmutable($row['first_submitted_at']),
            new DateTimeImmutable($row['updated_at']),
            $row['withdrawn_at'] !== null
            ? new DateTimeImmutable($row['withdrawn_at'])
            : null,
        );
    }
}
