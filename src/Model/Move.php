<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Move
{
    public function __construct(
        public int $studentId,
        public int $currentGroupId,
        public int $targetGroupId,
        public int $preferenceRank,
        public DateTimeImmutable $firstSubmittedAt,
    ) {
        if ($currentGroupId === $targetGroupId) {
            throw new InvalidArgumentException(
                'Le groupe cible doit être différent du groupe actuel.'
            );
        }

        if ($preferenceRank < 1 || $preferenceRank > 3) {
            throw new InvalidArgumentException(
                'Le rang de préférence doit être compris entre 1 et 3.'
            );
        }
    }
}