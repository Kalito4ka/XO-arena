<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class MatchmakingEntry
{
    public function __construct(
        public int $userId,
        public string $mode,
        public ?string $gameId,
        public string $queuedAt,
        public string $seenAt,
    ) {}

    public function isMatched(): bool
    {
        return $this->gameId !== null;
    }
}
