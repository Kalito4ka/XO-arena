<?php

declare(strict_types=1);

namespace App\Entity;

use InvalidArgumentException;

// Сохранённая партия. Правила отдельных режимов находятся в App\Game.
final readonly class Game
{
    public function __construct(
        public string $id,
        public string $mode,
        public array $moves,
        public string $status,
        public ?string $winner,
        public ?int $xUserId,
        public ?int $oUserId,
        private string $xTokenHash,
        private ?string $oTokenHash,
        private ?string $xGuestKey,
        private ?string $oGuestKey,
        public string $createdAt,
        public string $updatedAt,
        public ?string $finishedAt,
        public ?string $statsRecordedAt,
        public ?string $endReason,
        public bool $rematchX,
        public bool $rematchO,
        public ?string $rematchId,
    ) {}

    public function isWaiting(): bool
    {
        return $this->oTokenHash === null;
    }

    public function userIdFor(string $symbol): ?int
    {
        return match ($symbol) {
            'X' => $this->xUserId,
            'O' => $this->oUserId,
            default => throw new InvalidArgumentException('Допустимы только X и O.'),
        };
    }

    public function tokenHashFor(string $symbol): ?string
    {
        return match ($symbol) {
            'X' => $this->xTokenHash,
            'O' => $this->oTokenHash,
            default => throw new InvalidArgumentException('Допустимы только X и O.'),
        };
    }

    public function guestKeyFor(string $symbol): ?string
    {
        return match ($symbol) {
            'X' => $this->xGuestKey,
            'O' => $this->oGuestKey,
            default => throw new InvalidArgumentException('Допустимы только X и O.'),
        };
    }
}
