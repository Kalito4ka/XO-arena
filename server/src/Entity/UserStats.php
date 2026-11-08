<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class UserStats
{
    public function __construct(
        public int $userId,
        public string $mode,
        public int $wins = 0,
        public int $losses = 0,
        public int $draws = 0,
    ) {}

    public function played(): int
    {
        return $this->wins + $this->losses + $this->draws;
    }

    public function winRate(): float
    {
        return $this->played() === 0
            ? 0.0
            : round($this->wins * 100 / $this->played(), 2);
    }
}
