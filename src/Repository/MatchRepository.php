<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Repository;

use DateTimeImmutable;
use LBonnefond\TdSwap\Model\MatchingResult;
use LBonnefond\TdSwap\Model\MatchingSolution;
use PDO;

final class MatchRepository
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function saveAll(
        int $campaignId,
        MatchingSolution $solution,
        DateTimeImmutable $createdAt,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO matches (
                campaign_id,
                student_id,
                from_group_id,
                to_group_id,
                preference_rank,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($solution->moves as $move) {
            $statement->execute([
                $campaignId,
                $move->studentId,
                $move->currentGroupId,
                $move->targetGroupId,
                $move->preferenceRank,
                $createdAt->format(DATE_ATOM),
            ]);
        }
    }

    /**
     * @return list<MatchingResult>
     */
    public function findByCampaign(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                student_id,
                from_group_id,
                to_group_id,
                preference_rank,
                created_at
             FROM matches
             WHERE campaign_id = ?
             ORDER BY id ASC'
        );

        $statement->execute([$campaignId]);

        $results = [];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $results[] = new MatchingResult(
                (int) $row['id'],
                (int) $row['campaign_id'],
                (int) $row['student_id'],
                (int) $row['from_group_id'],
                (int) $row['to_group_id'],
                (int) $row['preference_rank'],
                new DateTimeImmutable($row['created_at']),
            );
        }

        return $results;
    }

    public function deleteByCampaign(int $campaignId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM matches
             WHERE campaign_id = ?'
        );

        $statement->execute([$campaignId]);
    }
}