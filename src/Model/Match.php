<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Match
{
    public function __construct(
        public int $id,
        public int $campaignId,
        public int $studentId,
        public int $fromGroupId,
        public int $toGroupId,
        public int $preferenceRank,
        public DateTimeImmutable $createdAt,
    ) {
        if ($fromGroupId === $toGroupId) {
            throw new InvalidArgumentException(
                'Un match doit déplacer un étudiant vers un autre groupe.'
            );
        }

        if ($preferenceRank < 1 || $preferenceRank > 3) {
            throw new InvalidArgumentException(
                'Le rang de préférence doit être compris entre 1 et 3.'
            );
        }
    }
}