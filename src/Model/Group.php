<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

final readonly class Group
{
    public function __construct(
        public int $id,
        public string $name,
        public int $capacity,
    ) {
        if ($capacity <= 0) {
            throw new \InvalidArgumentException(
                'La capacité du groupe doit être positive.'
            );
        }
    }
}