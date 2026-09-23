<?php
declare(strict_types=1);

namespace LBonnefond\TdSwap\Matching;

use LBonnefond\TdSwap\Model\MatchingSolution;
use LBonnefond\TdSwap\Model\Move;
use LBonnefond\TdSwap\Model\Request;

final class Matcher
{
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

        $best = new MatchingSolution([]);

        $this->search($requests, 0, [], $best);

        return $best;
    }

    /**
     * @param list<Request> $requests
     * @param list<Move> $moves
     */
    private function search(
        array $requests,
        int $index,
        array $moves,
        MatchingSolution &$best
    ): void {
        if ($index === count($requests)) {
            $solution = new MatchingSolution($moves);

            if (
                $solution->isBalanced()
                && $this->isBetter($solution, $best, $requests)
            ) {
                $best = $solution;
            }

            return;
        }

        // Ne pas satisfaire cette demande.
        $this->search(
            $requests,
            $index + 1,
            $moves,
            $best
        );

        $request = $requests[$index];

        foreach ($request->targetGroupIds as $rank => $targetGroupId) {
            $moves[] = new Move(
                $request->studentId,
                $request->currentGroupId,
                $targetGroupId,
                $rank + 1,
                $request->firstSubmittedAt
            );

            $this->search(
                $requests,
                $index + 1,
                $moves,
                $best
            );

            array_pop($moves);
        }
    }

    /**
     * Compare deux solutions selon les critères du projet :
     *
     * 1. nombre maximal de demandes satisfaites ;
     * 2. à cardinalité égale, priorité aux demandes les plus anciennes ;
     * 3. à satisfaction identique, meilleure préférence.
     *
     * @param list<Request> $requests
     */
    private function isBetter(
        MatchingSolution $candidate,
        MatchingSolution $currentBest,
        array $requests
    ): bool {
        if ($candidate->count() !== $currentBest->count()) {
            return $candidate->count() > $currentBest->count();
        }

        $candidateByStudent = $this->movesByStudent($candidate);
        $bestByStudent = $this->movesByStudent($currentBest);

        /*
         * Les requests sont déjà triées par ancienneté.
         * On compare donc leur satisfaction lexicographiquement :
         * satisfaire la plus ancienne est prioritaire.
         */
        foreach ($requests as $request) {
            $candidateMove = $candidateByStudent[$request->studentId] ?? null;
            $bestMove = $bestByStudent[$request->studentId] ?? null;

            if (($candidateMove !== null) !== ($bestMove !== null)) {
                return $candidateMove !== null;
            }
        }

        /*
         * Les mêmes demandes sont satisfaites.
         * On compare alors leur rang de préférence.
         */
        foreach ($requests as $request) {
            $candidateMove = $candidateByStudent[$request->studentId] ?? null;
            $bestMove = $bestByStudent[$request->studentId] ?? null;

            if ($candidateMove === null || $bestMove === null) {
                continue;
            }

            if ($candidateMove->preferenceRank !== $bestMove->preferenceRank) {
                return $candidateMove->preferenceRank
                    < $bestMove->preferenceRank;
            }
        }

        return false;
    }

    /**
     * @return array<int, Move>
     */
    private function movesByStudent(MatchingSolution $solution): array
    {
        $result = [];

        foreach ($solution->moves as $move) {
            $result[$move->studentId] = $move;
        }

        return $result;
    }
}