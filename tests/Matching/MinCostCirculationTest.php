<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Tests\Matching;

use LBonnefond\TdSwap\Matching\MinCostCirculation;
use PHPUnit\Framework\TestCase;

final class MinCostCirculationTest extends TestCase
{
    public function testFindsNegativeCycle(): void
    {
        $graph = new MinCostCirculation(3);

        $graph->addEdge(0, 1, 1, 0);
        $graph->addEdge(1, 2, 1, 0);
        $graph->addEdge(2, 0, 1, -1);

        self::assertSame(-1, $graph->solve());
    }

    public function testDoesNotUsePositiveCycle(): void
    {
        $graph = new MinCostCirculation(3);

        $graph->addEdge(0, 1, 1, 1);
        $graph->addEdge(1, 2, 1, 1);
        $graph->addEdge(2, 0, 1, 1);

        self::assertSame(0, $graph->solve());
    }

    public function testChoosesTwoNegativeCyclesWhenTheyAreIndependent(): void
    {
        $graph = new MinCostCirculation(6);

        $graph->addEdge(0, 1, 1, 0);
        $graph->addEdge(1, 2, 1, 0);
        $graph->addEdge(2, 0, 1, -1);

        $graph->addEdge(3, 4, 1, 0);
        $graph->addEdge(4, 5, 1, 0);
        $graph->addEdge(5, 3, 1, -1);

        self::assertSame(-2, $graph->solve());
    }

    public function testCanForceAnEdgeWithALowerBound(): void
    {
        $graph = new MinCostCirculation(3);

        // Cette arête doit obligatoirement être utilisée.
        $graph->addEdgeWithLowerBound(
            0,
            1,
            1,
            1,
            -1
        );

        $graph->addEdge(
            1,
            2,
            1,
            0
        );

        $graph->addEdge(
            2,
            0,
            1,
            0
        );

        self::assertSame(-1, $graph->solve());
    }

    public function testLowerBoundFeasibilityUsesExistingResidualGraph(): void
    {
        $graph = new MinCostCirculation(3);

        // Ce flot doit obligatoirement être envoyé de 0 vers 1.
        $forcedEdge = $graph->addEdgeWithLowerBound(
            0,
            1,
            1,
            1,
            0
        );

        // Pour satisfaire la borne inférieure, il faut pouvoir
        // faire revenir le flot de 1 vers 0.
        $returnEdge = $graph->addEdge(
            1,
            2,
            1,
            0
        );

        $closingEdge = $graph->addEdge(
            2,
            0,
            1,
            0
        );

        $cost = $graph->solve();

        self::assertSame(0, $cost);

        // Le flot imposé sur 0 -> 1 doit être présent.
        self::assertSame(
            0,
            $graph->remainingCapacity(0, $forcedEdge)
        );

        // Le circuit de faisabilité doit également avoir utilisé
        // les deux autres arêtes.
        self::assertSame(
            0,
            $graph->remainingCapacity(1, $returnEdge)
        );

        self::assertSame(
            0,
            $graph->remainingCapacity(2, $closingEdge)
        );
    }
}
