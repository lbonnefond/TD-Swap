<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Request
{
    /**
     * @param list<int> $targetGroupIds
     */
    public function __construct(
        public int $id,
        public int $studentId,
        public int $currentGroupId,
        public array $targetGroupIds,
        public DateTimeImmutable $firstSubmittedAt,
    ) {
        if ($targetGroupIds === []) {
            throw new InvalidArgumentException(
                'Une demande doit contenir au moins un groupe cible.'
            );
        }

        if (count($targetGroupIds) > 3) {
            throw new InvalidArgumentException(
                'Une demande ne peut contenir plus de trois groupes cibles.'
            );
        }

        if (count($targetGroupIds) !== count(array_unique($targetGroupIds))) {
            throw new InvalidArgumentException(
                'Les groupes cibles doivent être distincts.'
            );
        }

        if (in_array($currentGroupId, $targetGroupIds, true)) {
            throw new InvalidArgumentException(
                'Le groupe actuel ne peut pas être une destination.'
            );
        }
    }

    public function preferenceRank(int $groupId): ?int
    {
        $rank = array_search($groupId, $this->targetGroupIds, true);

        return $rank === false ? null : $rank + 1;
    }
}