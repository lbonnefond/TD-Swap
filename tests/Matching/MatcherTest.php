<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Matching;

use DateTimeImmutable;
use LBonnefond\TdSwap\Matching\Matcher;
use LBonnefond\TdSwap\Model\Request;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    private Matcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new Matcher();
    }

    private function request(
        int $id,
        int $student,
        int $current,
        array $targets
    ): Request {
        return new Request(
            $id,
            $student,
            $current,
            $targets,
            new DateTimeImmutable('2026-09-01 10:00:00')
        );
    }

    public function testFindsTwoWayExchange(): void
    {
        $solution = $this->matcher->findBest([
            $this->request(1, 1, 10, [20]),
            $this->request(2, 2, 20, [10]),
        ]);

        self::assertSame(2, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testFindsThreeWayExchange(): void
    {
        $solution = $this->matcher->findBest([
            $this->request(1, 1, 10, [20]),
            $this->request(2, 2, 20, [30]),
            $this->request(3, 3, 30, [10]),
        ]);

        self::assertSame(3, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testMaximizesNumberOfSatisfiedRequests(): void
    {
        // Solution A : cycle de 2
        // 1 : A -> B
        // 2 : B -> A
        //
        // Solution B : cycle de 3
        // 1 : A -> B
        // 3 : B -> C
        // 4 : C -> A
        //
        // Les deux solutions utilisent le départ A -> B.
        // Le moteur doit donc choisir le cycle de 3.

        $solution = $this->matcher->findBest([
            $this->request(1, 1, 10, [20]),
            $this->request(2, 2, 20, [10]),
            $this->request(3, 3, 20, [30]),
            $this->request(4, 4, 30, [10]),
        ]);

        self::assertSame(3, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testUnmatchedRequestCanRemainUnmatched(): void
    {
        $solution = $this->matcher->findBest([
            $this->request(1, 1, 10, [20]),
        ]);

        self::assertSame(0, $solution->count());
    }

    public function testRequestedGroupMustBeUsed(): void
    {
        $solution = $this->matcher->findBest([
            $this->request(1, 1, 10, [20]),
            $this->request(2, 2, 30, [10]),
        ]);

        self::assertSame(0, $solution->count());
    }

    public function testPrioritizesOlderRequestsWhenCountIsEqual(): void
    {
        $oldDate = new DateTimeImmutable('2026-09-01 10:00:00');
        $newDate = new DateTimeImmutable('2026-09-02 10:00:00');

        $solution = $this->matcher->findBest([
            // Ancien cycle : A -> B -> C -> A
            new Request(1, 1, 10, [20], $oldDate),
            new Request(2, 2, 20, [30], $oldDate),
            new Request(3, 3, 30, [10], $oldDate),

            // Nouveau cycle concurrent partageant la demande 1 :
            // A -> B -> D -> A
            new Request(4, 4, 20, [40], $newDate),
            new Request(5, 5, 40, [10], $newDate),
        ]);

        self::assertSame(3, $solution->count());

        $studentIds = array_map(
            static fn($move): int => $move->studentId,
            $solution->moves
        );

        self::assertSame([1, 2, 3], $studentIds);
    }

    public function testPrioritizesBetterPreferenceWhenSatisfiedRequestsAreOtherwiseEqual(): void
    {
        $date = new DateTimeImmutable('2026-09-01 10:00:00');

        $solution = $this->matcher->findBest([
            // Peut aller en 20 (préférence 1) ou 30 (préférence 2)
            new Request(1, 1, 10, [20, 30], $date),

            // Permet le cycle via 20
            new Request(2, 2, 20, [10], $date),

            // Permet le cycle via 30
            new Request(3, 3, 30, [10], $date),
        ]);

        self::assertSame(2, $solution->count());

        $move = array_values(array_filter(
            $solution->moves,
            static fn($move): bool => $move->studentId === 1
        ))[0];

        self::assertSame(20, $move->targetGroupId);
        self::assertSame(1, $move->preferenceRank);
    }

    public function testPrioritizesBetterPreferenceWhenSameRequestsAreSatisfied(): void
    {
        $date = new DateTimeImmutable('2026-09-01 10:00:00');

        $solution = $this->matcher->findBest([
            // Étudiant 1 peut participer au cycle avec B (préférence 1)
            // ou au cycle avec C (préférence 2).
            new Request(1, 1, 10, [20, 30], $date),

            // Cycle via B.
            new Request(2, 2, 20, [10], $date),

            // Cycle via C.
            new Request(3, 3, 30, [10], $date),
        ]);

        self::assertSame(2, $solution->count());

        $studentOneMove = array_values(array_filter(
            $solution->moves,
            static fn($move): bool => $move->studentId === 1
        ))[0];

        self::assertSame(20, $studentOneMove->targetGroupId);
        self::assertSame(1, $studentOneMove->preferenceRank);
    }
}
