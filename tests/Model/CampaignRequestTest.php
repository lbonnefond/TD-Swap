<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use LBonnefond\TdSwap\Model\CampaignRequest;
use PHPUnit\Framework\TestCase;

final class CampaignRequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $date = new DateTimeImmutable('2026-10-02T10:00:00+02:00');

        $request = new CampaignRequest(
            1,
            10,
            20,
            [30, 31, 32],
            $date,
            $date,
        );

        self::assertSame(1, $request->id);
        self::assertSame(10, $request->campaignId);
        self::assertSame(20, $request->campaignStudentId);
        self::assertSame([30, 31, 32], $request->targetCampaignGroupIds);
        self::assertSame($date, $request->firstSubmittedAt);
        self::assertSame($date, $request->updatedAt);
        self::assertNull($request->withdrawnAt);
    }

    public function testAtLeastOneTargetIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CampaignRequest(
            null,
            1,
            1,
            [],
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    public function testAtMostThreeTargetsAreAllowed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CampaignRequest(
            null,
            1,
            1,
            [10, 11, 12, 13],
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }

    public function testTargetsMustBeDistinct(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CampaignRequest(
            null,
            1,
            1,
            [10, 10],
            new DateTimeImmutable(),
            new DateTimeImmutable(),
        );
    }
}
