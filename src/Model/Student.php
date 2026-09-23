<?php

declare(strict_types=1);

namespace LBonnefond\TdSwap\Model;

final readonly class Student
{
    public function __construct(
        public int $id,
        public string $studentNumber,
        public string $surname,
        public string $firstName,
        public int $profileId,
        public int $initialGroupId,
    ) {
    }
}