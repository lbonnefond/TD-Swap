<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Matching;

use DateTimeImmutable;
use LBonnefond\TdSwap\Matching\Matcher;
use LBonnefond\TdSwap\Matching\ScalableMatcher;
use LBonnefond\TdSwap\Model\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use LBonnefond\TdSwap\Model\Move;

final class ScalableMatcherTest extends TestCase
{
    private ScalableMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ScalableMatcher();
    }

    public function testFindsTwoWayExchange(): void
    {
        $solution = $this->matcher->findBest([
            new Request(
                1,
                1,
                10,
                [20],
                new DateTimeImmutable('2026-09-01 10:00:00')
            ),
            new Request(
                2,
                2,
                20,
                [10],
                new DateTimeImmutable('2026-09-01 10:01:00')
            ),
        ]);

        self::assertSame(2, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testFindsThreeWayExchange(): void
    {
        $solution = $this->matcher->findBest([
            new Request(
                1,
                1,
                10,
                [20],
                new DateTimeImmutable('2026-09-01 10:00:00')
            ),
            new Request(
                2,
                2,
                20,
                [30],
                new DateTimeImmutable('2026-09-01 10:01:00')
            ),
            new Request(
                3,
                3,
                30,
                [10],
                new DateTimeImmutable('2026-09-01 10:02:00')
            ),
        ]);

        self::assertSame(3, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testMaximizesNumberOfSatisfiedRequests(): void
    {
        $solution = $this->matcher->findBest([
            new Request(
                1,
                1,
                10,
                [20],
                new DateTimeImmutable('2026-09-01 10:00:00')
            ),
            new Request(
                2,
                2,
                20,
                [10],
                new DateTimeImmutable('2026-09-01 10:01:00')
            ),
            new Request(
                3,
                3,
                20,
                [30],
                new DateTimeImmutable('2026-09-01 10:02:00')
            ),
            new Request(
                4,
                4,
                30,
                [10],
                new DateTimeImmutable('2026-09-01 10:03:00')
            ),
        ]);

        self::assertSame(3, $solution->count());
        self::assertTrue($solution->isBalanced());
    }

    public function testUnmatchedRequestCanRemainUnmatched(): void
    {
        $solution = $this->matcher->findBest([
            new Request(
                1,
                1,
                10,
                [20],
                new DateTimeImmutable('2026-09-01 10:00:00')
            ),
        ]);

        self::assertSame(0, $solution->count());
    }

    public function testProducesSameCardinalityAsReferenceMatcher(): void
    {
        $requests = [
            new Request(
                1,
                1,
                10,
                [20, 30],
                new DateTimeImmutable('2026-09-01 10:00:00')
            ),
            new Request(
                2,
                2,
                20,
                [10],
                new DateTimeImmutable('2026-09-01 10:01:00')
            ),
            new Request(
                3,
                3,
                30,
                [10],
                new DateTimeImmutable('2026-09-01 10:02:00')
            ),
        ];

        $reference = (new Matcher())->findBest($requests);
        $scalable = $this->matcher->findBest($requests);

        self::assertSame(
            $reference->count(),
            $scalable->count()
        );

        self::assertTrue($scalable->isBalanced());
    }

    public function testCanForceASpecificMove(): void
    {
        $requests = [
            new Request(
                1,
                101,
                1,
                [2],
                new DateTimeImmutable('2026-01-01 10:00:00')
            ),
            new Request(
                2,
                102,
                2,
                [1],
                new DateTimeImmutable('2026-01-01 11:00:00')
            ),
        ];

        $matcher = new ScalableMatcher();

        $reflection = new ReflectionClass($matcher);
        $method = $reflection->getMethod('solveWithConstraints');

        /** @var MatchingSolution $solution */
        $solution = $method->invoke(
            $matcher,
            $requests,
            [101 => 2],
            []
        );

        self::assertCount(2, $solution->moves);

        self::assertSame(
            2,
            $solution->moves[0]->targetGroupId
        );
    }

    public function testOlderRequestHasPriorityWhenOnlyOneMoveIsPossible(): void
    {
        $requests = [
            new Request(
                1,
                101,
                1,
                [2],
                new DateTimeImmutable('2026-01-01 10:00:00')
            ),
            new Request(
                2,
                102,
                1,
                [2],
                new DateTimeImmutable('2026-01-01 11:00:00')
            ),
            new Request(
                3,
                103,
                2,
                [1],
                new DateTimeImmutable('2026-01-01 12:00:00')
            ),
        ];

        $matcher = new ScalableMatcher();

        $solution = $matcher->findBest($requests);

        self::assertCount(2, $solution->moves);

        $studentIds = array_map(
            static fn(Move $move): int => $move->studentId,
            $solution->moves
        );

        self::assertContains(101, $studentIds);
        self::assertContains(103, $studentIds);
        self::assertNotContains(102, $studentIds);
    }

    public function testChronologicalPriorityMatchesReferenceMatcher(): void
    {
        $requests = [
            new Request(
                1,
                101,
                1,
                [2, 3],
                new DateTimeImmutable('2026-01-01 10:00:00')
            ),
            new Request(
                2,
                102,
                1,
                [2],
                new DateTimeImmutable('2026-01-01 11:00:00')
            ),
            new Request(
                3,
                103,
                2,
                [1],
                new DateTimeImmutable('2026-01-01 12:00:00')
            ),
            new Request(
                4,
                104,
                3,
                [1],
                new DateTimeImmutable('2026-01-01 13:00:00')
            ),
        ];

        $reference = new Matcher();
        $scalable = new ScalableMatcher();

        $referenceSolution = $reference->findBest($requests);
        $scalableSolution = $scalable->findBest($requests);

        self::assertSame(
            $referenceSolution->count(),
            $scalableSolution->count()
        );

        $referenceStudents = array_map(
            static fn(Move $move): int => $move->studentId,
            $referenceSolution->moves
        );

        $scalableStudents = array_map(
            static fn(Move $move): int => $move->studentId,
            $scalableSolution->moves
        );

        sort($referenceStudents);
        sort($scalableStudents);

        self::assertSame(
            $referenceStudents,
            $scalableStudents
        );
    }

    public function testPreferenceRankMatchesReferenceMatcher(): void
    {
        $requests = [
            new Request(
                1,
                101,
                1,
                [2, 3],
                new DateTimeImmutable('2026-01-01 10:00:00')
            ),
            new Request(
                2,
                102,
                2,
                [3],
                new DateTimeImmutable('2026-01-01 11:00:00')
            ),
            new Request(
                3,
                103,
                3,
                [1],
                new DateTimeImmutable('2026-01-01 12:00:00')
            ),
        ];

        $reference = new Matcher();
        $scalable = new ScalableMatcher();

        $referenceSolution = $reference->findBest($requests);
        $scalableSolution = $scalable->findBest($requests);

        self::assertSame(
            $referenceSolution->count(),
            $scalableSolution->count()
        );

        $referenceMoves = [];

        foreach ($referenceSolution->moves as $move) {
            $referenceMoves[$move->studentId] = $move->targetGroupId;
        }

        $scalableMoves = [];

        foreach ($scalableSolution->moves as $move) {
            $scalableMoves[$move->studentId] = $move->targetGroupId;
        }

        self::assertSame(
            $referenceMoves,
            $scalableMoves
        );
    }

    public function testScalableMatcherMatchesReferenceOnComplexCase(): void
    {
        $requests = [
            new Request(
                1,
                101,
                1,
                [2, 3],
                new DateTimeImmutable('2026-01-01 08:00:00')
            ),
            new Request(
                2,
                102,
                1,
                [3, 4],
                new DateTimeImmutable('2026-01-01 09:00:00')
            ),
            new Request(
                3,
                103,
                2,
                [1, 4],
                new DateTimeImmutable('2026-01-01 10:00:00')
            ),
            new Request(
                4,
                104,
                2,
                [3],
                new DateTimeImmutable('2026-01-01 11:00:00')
            ),
            new Request(
                5,
                105,
                3,
                [1, 2],
                new DateTimeImmutable('2026-01-01 12:00:00')
            ),
            new Request(
                6,
                106,
                3,
                [2],
                new DateTimeImmutable('2026-01-01 13:00:00')
            ),
            new Request(
                7,
                107,
                4,
                [1, 3],
                new DateTimeImmutable('2026-01-01 14:00:00')
            ),
        ];

        $reference = new Matcher();
        $scalable = new ScalableMatcher();

        $referenceSolution = $reference->findBest($requests);
        $scalableSolution = $scalable->findBest($requests);

        self::assertSame(
            $referenceSolution->count(),
            $scalableSolution->count()
        );

        $referenceMoves = [];

        foreach ($referenceSolution->moves as $move) {
            $referenceMoves[$move->studentId] = [
                $move->targetGroupId,
                $move->preferenceRank,
            ];
        }

        $scalableMoves = [];

        foreach ($scalableSolution->moves as $move) {
            $scalableMoves[$move->studentId] = [
                $move->targetGroupId,
                $move->preferenceRank,
            ];
        }

        ksort($referenceMoves);
        ksort($scalableMoves);

        self::assertSame(
            $referenceMoves,
            $scalableMoves
        );
    }

    public function testScalableMatcherMatchesReferenceOnRandomCases(): void
    {
        mt_srand(20260922);

        for ($case = 0; $case < 100; ++$case) {
            $groupCount = mt_rand(2, 5);
            $studentCount = mt_rand(2, 8);

            $requests = [];

            for ($student = 1; $student <= $studentCount; ++$student) {
                $currentGroup = mt_rand(1, $groupCount);

                $possibleTargets = [];

                for ($group = 1; $group <= $groupCount; ++$group) {
                    if ($group !== $currentGroup) {
                        $possibleTargets[] = $group;
                    }
                }

                shuffle($possibleTargets);

                $targetCount = mt_rand(
                    1,
                    min(3, count($possibleTargets))
                );

                $targets = array_slice(
                    $possibleTargets,
                    0,
                    $targetCount
                );

                $requests[] = new Request(
                    $student,
                    1000 + $student,
                    $currentGroup,
                    $targets,
                    new DateTimeImmutable(
                        sprintf(
                            '2026-01-01 %02d:00:00',
                            $student
                        )
                    )
                );
            }

            $reference = (new Matcher())->findBest($requests);
            $scalable = (new ScalableMatcher())->findBest($requests);

            $referenceMoves = [];

            foreach ($reference->moves as $move) {
                $referenceMoves[$move->studentId] = [
                    $move->targetGroupId,
                    $move->preferenceRank,
                ];
            }

            $scalableMoves = [];

            foreach ($scalable->moves as $move) {
                $scalableMoves[$move->studentId] = [
                    $move->targetGroupId,
                    $move->preferenceRank,
                ];
            }

            ksort($referenceMoves);
            ksort($scalableMoves);

            if ($case === 96) {
                fwrite(STDERR, "\n=== RANDOM CASE 96 ===\n");

                foreach ($requests as $request) {
                    fwrite(
                        STDERR,
                        sprintf(
                            "student=%d current=%d targets=[%s] time=%s\n",
                            $request->studentId,
                            $request->currentGroupId,
                            implode(',', $request->targetGroupIds),
                            $request->firstSubmittedAt->format('Y-m-d H:i:s')
                        )
                    );
                }

                fwrite(STDERR, "Reference:\n");
                foreach ($referenceMoves as $studentId => $move) {
                    fwrite(
                        STDERR,
                        sprintf(
                            "  %d -> %d (rank %d)\n",
                            $studentId,
                            $move[0],
                            $move[1]
                        )
                    );
                }

                fwrite(STDERR, "Scalable:\n");
                foreach ($scalableMoves as $studentId => $move) {
                    fwrite(
                        STDERR,
                        sprintf(
                            "  %d -> %d (rank %d)\n",
                            $studentId,
                            $move[0],
                            $move[1]
                        )
                    );
                }
            }

            $diagnostic = 'Divergence dans le cas aléatoire ' . $case . "\n";

            foreach ($requests as $request) {
                $diagnostic .= sprintf(
                    "student=%d current=%d targets=[%s] time=%s\n",
                    $request->studentId,
                    $request->currentGroupId,
                    implode(',', $request->targetGroupIds),
                    $request->firstSubmittedAt->format('Y-m-d H:i:s')
                );
            }

            $diagnostic .= 'Reference: ' . var_export(
                $referenceMoves,
                true
            ) . "\n";

            $diagnostic .= 'Scalable: ' . var_export(
                $scalableMoves,
                true
            ) . "\n";

            self::assertSame(
                $referenceMoves,
                $scalableMoves,
                $diagnostic
            );
        }
    }
}
