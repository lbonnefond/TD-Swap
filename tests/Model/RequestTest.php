<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use LBonnefond\TdSwap\Model\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testValidRequest(): void
    {
        $request = new Request(
            1,
            42,
            10,
            [20, 30, 40],
            new DateTimeImmutable('2026-09-01 10:00:00')
        );

        self::assertSame(20, $request->targetGroupIds[0]);
        self::assertSame(1, $request->preferenceRank(20));
        self::assertSame(3, $request->preferenceRank(40));
        self::assertNull($request->preferenceRank(50));
    }

    public function testAtLeastOneTargetIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request(
            1,
            42,
            10,
            [],
            new DateTimeImmutable()
        );
    }

    public function testMaximumThreeTargets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request(
            1,
            42,
            10,
            [20, 30, 40, 50],
            new DateTimeImmutable()
        );
    }

    public function testDuplicateTargetsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request(
            1,
            42,
            10,
            [20, 20],
            new DateTimeImmutable()
        );
    }

    public function testCurrentGroupCannotBeTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request(
            1,
            42,
            10,
            [20, 10],
            new DateTimeImmutable()
        );
    }
}