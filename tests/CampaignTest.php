<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests;

use LBonnefond\TdSwap\Model\Campaign;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CampaignTest extends TestCase
{
    public function testCampaignCanBeCreated(): void
    {
        $startsAt = new DateTimeImmutable('2026-10-01T08:00:00+02:00');
        $closesAt = new DateTimeImmutable('2026-10-10T18:00:00+02:00');

        $campaign = new Campaign(
            null,
            'Échanges TD semestre 1',
            $startsAt,
            $closesAt,
        );

        self::assertNull($campaign->id);
        self::assertSame(
            'Échanges TD semestre 1',
            $campaign->name
        );
        self::assertSame($startsAt, $campaign->startsAt);
        self::assertSame($closesAt, $campaign->closesAt);
        self::assertSame('draft', $campaign->status);
        self::assertNull($campaign->matchedAt);
    }

    public function testNameCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            null,
            '',
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
        );
    }

    public function testClosingDateMustBeAfterStartDate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            null,
            'Test',
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
        );
    }

    public function testStatusMustBeValid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            null,
            'Test',
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            'invalid',
        );
    }

    public function testMatchedCampaignRequiresMatchedAt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Campaign(
            null,
            'Test',
            new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
            new DateTimeImmutable('2026-10-10T18:00:00+02:00'),
            'matched',
        );
    }
}