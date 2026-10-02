<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Matching;

use RuntimeException;

final class MinCostCirculation
{
    /**
     * @var array<int, array<int, array{
     *     to: int,
     *     reverse: int,
     *     capacity: int,
     *     cost: int
     * }>>
     */
    private array $graph = [];

    private array $balance;

    private int $nodeCount;

    private int $lowerBoundCost = 0;

    public function __construct(int $nodeCount)
    {
        if ($nodeCount <= 0) {
            throw new \InvalidArgumentException(
                'Le graphe doit contenir au moins un sommet.'
            );
        }

        $this->nodeCount = $nodeCount;

        $this->balance = array_fill(0, $nodeCount, 0);

        for ($i = 0; $i < $nodeCount; ++$i) {
            $this->graph[$i] = [];
        }
    }

    public function addEdge(
        int $from,
        int $to,
        int $capacity,
        int $cost
    ): int {
        if ($from < 0 || $from >= $this->nodeCount) {
            throw new \OutOfRangeException('Sommet source invalide.');
        }

        if ($to < 0 || $to >= $this->nodeCount) {
            throw new \OutOfRangeException('Sommet destination invalide.');
        }

        if ($capacity < 0) {
            throw new \InvalidArgumentException(
                'La capacité ne peut pas être négative.'
            );
        }

        $forwardIndex = count($this->graph[$from]);
        $reverseIndex = count($this->graph[$to]);

        $this->graph[$from][] = [
            'to' => $to,
            'reverse' => $reverseIndex,
            'capacity' => $capacity,
            'cost' => $cost,
        ];

        $this->graph[$to][] = [
            'to' => $from,
            'reverse' => $forwardIndex,
            'capacity' => 0,
            'cost' => -$cost,
        ];

        return $forwardIndex;
    }

    /**
     * Cherche et annule successivement les cycles de coût négatif.
     *
     * @return int coût total de la circulation obtenue
     */

    public function solve(): int
    {
        $originalNodeCount = $this->nodeCount;

        $superSource = $this->nodeCount++;
        $superSink = $this->nodeCount++;

        $this->graph[$superSource] = [];
        $this->graph[$superSink] = [];

        $temporaryEdges = [];
        $requiredFlow = 0;

        foreach ($this->balance as $node => $balance) {
            if ($balance > 0) {
                $edgeIndex = $this->addEdge(
                    $superSource,
                    $node,
                    $balance,
                    0
                );

                $temporaryEdges[] = [$superSource, $edgeIndex];
                $requiredFlow += $balance;
            } elseif ($balance < 0) {
                $edgeIndex = $this->addEdge(
                    $node,
                    $superSink,
                    -$balance,
                    0
                );

                $temporaryEdges[] = [$node, $edgeIndex];
            }
        }

        if ($requiredFlow > 0) {
            $flow = $this->findRequiredFlow(
                $superSource,
                $superSink,
                $requiredFlow,
                $originalNodeCount
            );

            if ($flow !== $requiredFlow) {
                $this->removeTemporaryEdges($temporaryEdges);
                $this->nodeCount = $originalNodeCount;
                unset($this->graph[$superSource], $this->graph[$superSink]);

                throw new \RuntimeException(
                    'Aucune circulation réalisable ne satisfait les bornes inférieures.'
                );
            }
        }

        $this->removeTemporaryEdges($temporaryEdges);

        $this->nodeCount = $originalNodeCount;
        unset($this->graph[$superSource], $this->graph[$superSink]);

        $totalCost = $this->lowerBoundCost;

        while (($cycle = $this->findNegativeCycle()) !== null) {
            $bottleneck = PHP_INT_MAX;

            foreach ($cycle as [$from, $edgeIndex]) {
                $edge = $this->graph[$from][$edgeIndex];
                $bottleneck = min($bottleneck, $edge['capacity']);
            }

            foreach ($cycle as [$from, $edgeIndex]) {
                $to = $this->graph[$from][$edgeIndex]['to'];
                $cost = $this->graph[$from][$edgeIndex]['cost'];

                $this->graph[$from][$edgeIndex]['capacity'] -= $bottleneck;

                $reverseIndex =
                    $this->graph[$from][$edgeIndex]['reverse'];

                $this->graph[$to][$reverseIndex]['capacity'] += $bottleneck;

                $totalCost += $bottleneck * $cost;
            }
        }

        return $totalCost;
    }

    /**
     * Retourne la capacité résiduelle d'une arête.
     */
    public function remainingCapacity(
        int $from,
        int $edgeIndex
    ): int {
        return $this->graph[$from][$edgeIndex]['capacity'];
    }

    /**
     * @return list<array{int, int}>|null
     */
    private function findNegativeCycle(): ?array
    {
        $distance = array_fill(0, $this->nodeCount, 0);
        $predecessorNode = array_fill(0, $this->nodeCount, null);
        $predecessorEdge = array_fill(0, $this->nodeCount, null);

        $updated = null;

        for ($iteration = 0; $iteration < $this->nodeCount; ++$iteration) {
            $updated = null;

            for ($from = 0; $from < $this->nodeCount; ++$from) {
                foreach ($this->graph[$from] as $edgeIndex => $edge) {
                    if ($edge['capacity'] <= 0) {
                        continue;
                    }

                    $to = $edge['to'];
                    $newDistance = $distance[$from] + $edge['cost'];

                    if ($newDistance < $distance[$to]) {
                        $distance[$to] = $newDistance;
                        $predecessorNode[$to] = $from;
                        $predecessorEdge[$to] = $edgeIndex;
                        $updated = $to;
                    }
                }
            }

            if ($updated === null) {
                return null;
            }
        }

        /*
         * Après |V| relaxations, updated appartient à un cycle négatif.
         * On remonte |V| fois pour être certain d'être dans le cycle.
         */
        $node = $updated;

        for ($i = 0; $i < $this->nodeCount; ++$i) {
            $node = $predecessorNode[$node];

            if ($node === null) {
                return null;
            }
        }

        $cycleStart = $node;
        $cycle = [];

        do {
            $from = $predecessorNode[$node];
            $edgeIndex = $predecessorEdge[$node];

            if ($from === null || $edgeIndex === null) {
                return null;
            }

            $cycle[] = [$from, $edgeIndex];
            $node = $from;
        } while ($node !== $cycleStart);

        return $cycle;
    }

    public function addEdgeWithLowerBound(
        int $from,
        int $to,
        int $lowerBound,
        int $upperBound,
        int $cost
    ): int {
        if ($lowerBound < 0) {
            throw new \InvalidArgumentException(
                'La borne inférieure ne peut pas être négative.'
            );
        }

        if ($upperBound < $lowerBound) {
            throw new \InvalidArgumentException(
                'La borne supérieure doit être >= à la borne inférieure.'
            );
        }

        $this->balance[$from] -= $lowerBound;
        $this->balance[$to] += $lowerBound;

        $this->lowerBoundCost += $lowerBound * $cost;

        return $this->addEdge(
            $from,
            $to,
            $upperBound - $lowerBound,
            $cost
        );
    }

    private function findRequiredFlow(
        int $source,
        int $sink,
        int $requiredFlow,
        int $originalNodeCount
    ): int {
        $flow = 0;

        while ($flow < $requiredFlow) {
            $parentNode = array_fill(0, $this->nodeCount, -1);
            $parentEdge = array_fill(0, $this->nodeCount, -1);

            $queue = [$source];
            $parentNode[$source] = $source;

            for ($head = 0; $head < count($queue); ++$head) {
                $node = $queue[$head];

                foreach ($this->graph[$node] as $edgeIndex => $edge) {
                    if ($edge['capacity'] <= 0) {
                        continue;
                    }

                    $next = $edge['to'];

                    if ($parentNode[$next] !== -1) {
                        continue;
                    }

                    $parentNode[$next] = $node;
                    $parentEdge[$next] = $edgeIndex;

                    if ($next === $sink) {
                        break 2;
                    }

                    $queue[] = $next;
                }
            }

            if ($parentNode[$sink] === -1) {
                break;
            }

            $pathFlow = $requiredFlow - $flow;
            $node = $sink;

            while ($node !== $source) {
                $previous = $parentNode[$node];
                $edgeIndex = $parentEdge[$node];

                $pathFlow = min(
                    $pathFlow,
                    $this->graph[$previous][$edgeIndex]['capacity']
                );

                $node = $previous;
            }

            $node = $sink;

            while ($node !== $source) {
                $previous = $parentNode[$node];
                $edgeIndex = $parentEdge[$node];

                $reverseIndex =
                    $this->graph[$previous][$edgeIndex]['reverse'];

                $this->graph[$previous][$edgeIndex]['capacity']
                    -= $pathFlow;

                $this->graph[$node][$reverseIndex]['capacity']
                    += $pathFlow;

                $node = $previous;
            }

            $flow += $pathFlow;
        }

        return $flow;
    }

    /**
     * @param list<array{0:int,1:int}> $temporaryEdges
     */
    private function removeTemporaryEdges(array $temporaryEdges): void
    {
        foreach ($temporaryEdges as [$from, $edgeIndex]) {
            $to = $this->graph[$from][$edgeIndex]['to'];
            $reverseIndex =
                $this->graph[$from][$edgeIndex]['reverse'];

            $this->graph[$from][$edgeIndex]['capacity'] = 0;
            $this->graph[$to][$reverseIndex]['capacity'] = 0;
        }
    }
}