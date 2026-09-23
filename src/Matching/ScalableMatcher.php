<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Matching;

use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Model\Move;
use LBonnefond\TdSwap\Model\Request;

final class ScalableMatcher
{
    /**
     * @param list<Request> $requests
     * @param array<int, int> $forcedTargets
     * @param array<int, bool> $excludedStudents
     * @param list<int> $forcedStudents
     */
    private function solveWithConstraints(
        array $requests,
        array $forcedTargets = [],
        array $excludedStudents = [],
        array $forcedStudents = []
    ): ?MatchingSolution {
        if ($requests === []) {
            return new MatchingSolution([]);
        }

        $groupIds = [];

        foreach ($requests as $request) {
            if (isset($excludedStudents[$request->studentId])) {
                continue;
            }

            $groupIds[$request->currentGroupId] = true;

            foreach ($request->targetGroupIds as $groupId) {
                $groupIds[$groupId] = true;
            }
        }

        $groupNode = [];
        $nextNode = 0;

        foreach (array_keys($groupIds) as $groupId) {
            $groupNode[$groupId] = $nextNode++;
        }

        $studentNode = [];

        foreach ($requests as $request) {
            if (isset($excludedStudents[$request->studentId])) {
                continue;
            }

            $studentNode[$request->studentId] = $nextNode++;
        }

        if ($nextNode === 0) {
            return new MatchingSolution([]);
        }

        $graph = new MinCostCirculation($nextNode);

        $requestEdges = [];

        foreach ($requests as $request) {
            if (isset($excludedStudents[$request->studentId])) {
                continue;
            }

            $student = $studentNode[$request->studentId];
            $currentGroup = $groupNode[$request->currentGroupId];

            /*
             * A forced student must make a move.
             * Therefore the current-group -> student edge
             * is forced to carry one unit.
             */
            if (in_array($request->studentId, $forcedStudents, true)) {
                $graph->addEdgeWithLowerBound(
                    $currentGroup,
                    $student,
                    1,
                    1,
                    0
                );
            } else {
                $graph->addEdge(
                    $currentGroup,
                    $student,
                    1,
                    0
                );
            }

            foreach ($request->targetGroupIds as $targetGroupId) {
                $isForced =
                    isset($forcedTargets[$request->studentId])
                    && $forcedTargets[$request->studentId] === $targetGroupId;

                if ($isForced) {
                    $edgeIndex = $graph->addEdgeWithLowerBound(
                        $student,
                        $groupNode[$targetGroupId],
                        1,
                        1,
                        -1
                    );
                } else {
                    $edgeIndex = $graph->addEdge(
                        $student,
                        $groupNode[$targetGroupId],
                        1,
                        -1
                    );
                }

                $requestEdges[$request->studentId][] = [
                    'request' => $request,
                    'targetGroupId' => $targetGroupId,
                    'edgeIndex' => $edgeIndex,
                ];
            }
        }

        try {
            $graph->solve();
        } catch (\RuntimeException $exception) {
            if (
                $exception->getMessage()
                === 'Aucune circulation réalisable ne satisfait les bornes inférieures.'
            ) {
                return null;
            }

            throw $exception;
        }

        $moves = [];

        foreach ($requestEdges as $studentEdges) {
            foreach ($studentEdges as $edge) {
                if (
                    $graph->remainingCapacity(
                        $studentNode[$edge['request']->studentId],
                        $edge['edgeIndex']
                    ) !== 0
                ) {
                    continue;
                }

                $request = $edge['request'];

                $preferenceRank = $request->preferenceRank(
                    $edge['targetGroupId']
                );

                if ($preferenceRank === null) {
                    continue;
                }

                $moves[] = new Move(
                    $request->studentId,
                    $request->currentGroupId,
                    $edge['targetGroupId'],
                    $preferenceRank,
                    $request->firstSubmittedAt
                );
            }
        }

        return new MatchingSolution($moves);
    }

    /**
     * @param list<Request> $requests
     */
    public function findBest(array $requests): MatchingSolution
    {
        usort(
            $requests,
            static function (Request $a, Request $b): int {
                $comparison = $a->firstSubmittedAt <=> $b->firstSubmittedAt;

                if ($comparison !== 0) {
                    return $comparison;
                }

                return $a->id <=> $b->id;
            }
        );

        /*
         * Étape 1 :
         * déterminer le nombre maximal de mouvements.
         */
        $initialSolution = $this->solveWithConstraints($requests);

        if ($initialSolution === null) {
            throw new \RuntimeException(
                'Aucune solution réalisable pour les demandes.'
            );
        }

        $maxMoves = $initialSolution->count();

        /*
         * Étape 2 :
         * déterminer lexicographiquement quels étudiants
         * doivent être satisfaits.
         *
         * On force uniquement l'étudiant à effectuer un mouvement,
         * sans fixer encore sa destination.
         */
        $forcedStudents = [];
        $excludedStudents = [];

        foreach ($requests as $request) {
            $studentId = $request->studentId;

            $candidateForcedStudents = $forcedStudents;
            $candidateForcedStudents[] = $studentId;

            $candidate = $this->solveWithConstraints(
                $requests,
                [],
                $excludedStudents,
                $candidateForcedStudents
            );

            if (
                $candidate !== null
                && $candidate->count() === $maxMoves
            ) {
                /*
                 * Cet étudiant peut être satisfait tout en conservant
                 * le cardinal maximal.
                 */
                $forcedStudents[] = $studentId;
            } else {
                /*
                 * Le satisfaire ferait perdre au moins un mouvement.
                 */
                $excludedStudents[$studentId] = true;
            }
        }

        /*
         * Étape 3 :
         * l'ensemble des étudiants satisfaits est maintenant fixé.
         *
         * On choisit ensuite les destinations dans l'ordre
         * chronologique, en privilégiant le meilleur rang possible.
         */
        $forcedTargets = [];

        foreach ($requests as $request) {
            $studentId = $request->studentId;

            if (!in_array($studentId, $forcedStudents, true)) {
                continue;
            }

            $selectedTarget = null;

            foreach ($request->targetGroupIds as $targetGroupId) {
                $candidateForcedTargets = $forcedTargets;
                $candidateForcedTargets[$studentId] = $targetGroupId;

                $candidate = $this->solveWithConstraints(
                    $requests,
                    $candidateForcedTargets,
                    $excludedStudents,
                    $forcedStudents
                );

                if (
                    $candidate !== null
                    && $candidate->count() === $maxMoves
                ) {
                    $selectedTarget = $targetGroupId;
                    break;
                }
            }

            if ($selectedTarget === null) {
                throw new \RuntimeException(
                    sprintf(
                        'Impossible de déterminer une destination pour l’étudiant %d.',
                        $studentId
                    )
                );
            }

            $forcedTargets[$studentId] = $selectedTarget;
        }

        /*
         * Étape 4 :
         * résolution finale avec toutes les contraintes fixées.
         */
        $solution = $this->solveWithConstraints(
            $requests,
            $forcedTargets,
            $excludedStudents,
            $forcedStudents
        );

        if ($solution === null || $solution->count() !== $maxMoves) {
            throw new \RuntimeException(
                'La résolution finale ne respecte pas les contraintes.'
            );
        }

        return $solution;
    }
}