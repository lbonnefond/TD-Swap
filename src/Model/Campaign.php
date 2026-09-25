<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

final class Campaign
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly \DateTimeImmutable $startsAt,
        public readonly \DateTimeImmutable $closesAt,
        public readonly string $status = 'draft',
        public readonly ?\DateTimeImmutable $matchedAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException(
                'Le nom de la campagne ne peut pas être vide.'
            );
        }

        if ($this->closesAt <= $this->startsAt) {
            throw new \InvalidArgumentException(
                'La date de fermeture doit être postérieure à la date d’ouverture.'
            );
        }

        if (!in_array(
            $this->status,
            ['draft', 'open', 'closed', 'matched'],
            true
        )) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Statut de campagne invalide : %s',
                    $this->status
                )
            );
        }

        if ($this->status === 'matched' && $this->matchedAt === null) {
            throw new \InvalidArgumentException(
                'Une campagne matched doit avoir une date de matching.'
            );
        }
    }
}