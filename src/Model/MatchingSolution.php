<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

final readonly class MatchingSolution
{
    /**
     * @param list<Move> $moves
     */
    public function __construct(
        public array $moves,
    ) {
        $studentIds = array_map(
            static fn (Move $move): int => $move->studentId,
            $moves
        );

        if (count($studentIds) !== count(array_unique($studentIds))) {
            throw new \InvalidArgumentException(
                'Un étudiant ne peut apparaître qu’une seule fois dans une solution.'
            );
        }
    }

    public function count(): int
    {
        return count($this->moves);
    }

    public function isBalanced(): bool
    {
        $balance = [];

        foreach ($this->moves as $move) {
            $balance[$move->currentGroupId] =
                ($balance[$move->currentGroupId] ?? 0) - 1;

            $balance[$move->targetGroupId] =
                ($balance[$move->targetGroupId] ?? 0) + 1;
        }

        return array_filter(
            $balance,
            static fn (int $value): bool => $value !== 0
        ) === [];
    }
}