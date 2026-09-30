<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Model;

use DateTimeImmutable;
use LBonnefond\TdSwap\Model\MatchingResult;
use PHPUnit\Framework\TestCase;

final class MatchingResultTest extends TestCase
{
    public function testValidMatchingResult(): void
    {
        $result = new MatchingResult(
            1,
            10,
            100,
            3,
            4,
            2,
            new DateTimeImmutable('2026-01-01')
        );

        self::assertSame(100, $result->studentId);
        self::assertSame(4, $result->toGroupId);
    }
}