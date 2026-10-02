<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Repository;

use DateTimeImmutable;
use LBonnefond\TdSwap\Model\Campaign;
use PDO;

final class CampaignRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function create(Campaign $campaign): Campaign
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO campaigns (
                name,
                starts_at,
                closes_at,
                status,
                matched_at,
                created_at,
                access_code
            ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $createdAt = $campaign->createdAt
            ?? new DateTimeImmutable();

        $statement->execute([
            $campaign->name,
            $campaign->startsAt->format(DATE_ATOM),
            $campaign->closesAt->format(DATE_ATOM),
            $campaign->status,
            $campaign->matchedAt?->format(DATE_ATOM),
            $createdAt->format(DATE_ATOM),
            $campaign->accessCode,
        ]);

        return new Campaign(
            (int) $this->pdo->lastInsertId(),
            $campaign->name,
            $campaign->startsAt,
            $campaign->closesAt,
            $campaign->status,
            $campaign->matchedAt,
            $createdAt,
            $campaign->accessCode,
        );
    }

    public function findById(int $id): ?Campaign
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                name,
                starts_at,
                closes_at,
                status,
                matched_at,
                created_at,
                access_code
             FROM campaigns
             WHERE id = ?'
        );

        $statement->execute([$id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function updateStatus(
        int $id,
        string $status,
        ?DateTimeImmutable $matchedAt = null,
    ): Campaign {
        $statement = $this->pdo->prepare(
            'UPDATE campaigns
         SET status = ?, matched_at = ?
         WHERE id = ?'
        );

        $statement->execute([
            $status,
            $matchedAt?->format(DATE_ATOM),
            $id,
        ]);

        $campaign = $this->findById($id);

        if ($campaign === null) {
            throw new \RuntimeException(
                sprintf('Campagne introuvable : %d', $id)
            );
        }

        return $campaign;
    }

    private function hydrate(array $row): Campaign
    {
        return new Campaign(
            (int) $row['id'],
            $row['name'],
            new DateTimeImmutable($row['starts_at']),
            new DateTimeImmutable($row['closes_at']),
            $row['status'],
            $row['matched_at'] !== null
                ? new DateTimeImmutable($row['matched_at'])
                : null,
            $row['created_at'] !== null
                ? new DateTimeImmutable($row['created_at'])
                : null,
            $row['access_code'],
        );
    }

    public function findAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT
            id,
            name,
            starts_at,
            closes_at,
            status,
            matched_at,
            created_at,
            access_code
         FROM campaigns
         ORDER BY starts_at DESC, id DESC'
        );

        return $statement->fetchAll();
    }
}
