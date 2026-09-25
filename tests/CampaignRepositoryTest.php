<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use DateTimeImmutable;
use LBonnefond\TdSwap\Database\Schema;
use LBonnefond\TdSwap\Model\Campaign;
use LBonnefond\TdSwap\Repository\CampaignRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class CampaignRepositoryTest extends TestCase
{
    private PDO $pdo;
    private CampaignRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        Schema::create($this->pdo);

        $this->repository = new CampaignRepository($this->pdo);
    }

    public function testCampaignCanBeCreated(): void
    {
        $createdAt = new DateTimeImmutable(
            '2026-09-24T10:00:00+02:00'
        );

        $campaign = new Campaign(
            null,
            'Échanges TD semestre 1',
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            'draft',
            null,
            $createdAt,
        );

        $saved = $this->repository->create($campaign);

        self::assertNotNull($saved->id);
        self::assertSame($campaign->name, $saved->name);
        self::assertSame($campaign->startsAt, $saved->startsAt);
        self::assertSame($campaign->closesAt, $saved->closesAt);
        self::assertSame('draft', $saved->status);
        self::assertSame($createdAt, $saved->createdAt);
    }

    public function testCampaignCanBeFoundById(): void
    {
        $campaign = new Campaign(
            null,
            'Échanges TD semestre 1',
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            'draft',
            null,
            new DateTimeImmutable('2026-09-24T10:00:00+02:00'),
        );

        $saved = $this->repository->create($campaign);

        $found = $this->repository->findById($saved->id);

        self::assertNotNull($found);
        self::assertSame($saved->id, $found->id);
        self::assertSame($saved->name, $found->name);
        self::assertSame(
            $saved->startsAt->format(DATE_ATOM),
            $found->startsAt->format(DATE_ATOM)
        );

        self::assertSame(
            $saved->closesAt->format(DATE_ATOM),
            $found->closesAt->format(DATE_ATOM)
        );

        self::assertSame($saved->status, $found->status);

        self::assertSame(
            $saved->createdAt?->format(DATE_ATOM),
            $found->createdAt?->format(DATE_ATOM)
        );
    }

    public function testUnknownCampaignReturnsNull(): void
    {
        self::assertNull(
            $this->repository->findById(999)
        );
    }
}
