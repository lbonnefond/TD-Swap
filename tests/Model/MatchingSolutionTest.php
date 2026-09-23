<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Model\Move;
use PHPUnit\Framework\TestCase;

final class MatchingSolutionTest extends TestCase
{
    private function move(
        int $studentId,
        int $current,
        int $target
    ): Move {
        return new Move(
            $studentId,
            $current,
            $target,
            1,
            new DateTimeImmutable('2026-09-01 10:00:00')
        );
    }

    public function testTwoWayExchangeIsBalanced(): void
    {
        $solution = new MatchingSolution([
            $this->move(1, 10, 20),
            $this->move(2, 20, 10),
        ]);

        self::assertSame(2, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testThreeWayExchangeIsBalanced(): void
    {
        $solution = new MatchingSolution([
            $this->move(1, 10, 20),
            $this->move(2, 20, 30),
            $this->move(3, 30, 10),
        ]);

        self::assertTrue($solution->isBalanced());
    }

    public function testUnbalancedMovesAreRejectedByBalanceCheck(): void
    {
        $solution = new MatchingSolution([
            $this->move(1, 10, 20),
            $this->move(2, 20, 30),
        ]);

        self::assertFalse($solution->isBalanced());
    }

    public function testSameStudentCannotAppearTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MatchingSolution([
            $this->move(1, 10, 20),
            $this->move(1, 10, 30),
        ]);
    }
}