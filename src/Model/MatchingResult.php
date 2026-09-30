<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

use DateTimeImmutable;

final readonly class MatchingResult
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
    }
}