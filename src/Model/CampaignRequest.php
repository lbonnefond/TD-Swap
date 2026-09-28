<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CampaignRequest
{
    /**
     * @param list<int> $targetCampaignGroupIds
     */
    public function __construct(
        public ?int $id,
        public int $campaignId,
        public int $campaignStudentId,
        public array $targetCampaignGroupIds,
        public DateTimeImmutable $firstSubmittedAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $withdrawnAt = null,
    ) {
        if ($this->campaignId <= 0) {
            throw new InvalidArgumentException(
                'L’identifiant de campagne doit être positif.'
            );
        }

        if ($this->campaignStudentId <= 0) {
            throw new InvalidArgumentException(
                'L’identifiant de l’étudiant de campagne doit être positif.'
            );
        }

        if ($targetCampaignGroupIds === []) {
            throw new InvalidArgumentException(
                'Une demande doit contenir au moins un groupe cible.'
            );
        }

        if (count($targetCampaignGroupIds) > 3) {
            throw new InvalidArgumentException(
                'Une demande ne peut contenir plus de trois groupes cibles.'
            );
        }

        if (count($targetCampaignGroupIds) !== count(array_unique($targetCampaignGroupIds))) {
            throw new InvalidArgumentException(
                'Les groupes cibles doivent être distincts.'
            );
        }
    }
}
