<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use App\Repository\GameRepository;
use App\Repository\MatchmakingRepository;
use PDO;

require_once __DIR__ . '/../Repository/GameRepository.php';
require_once __DIR__ . '/../Repository/MatchmakingRepository.php';

final class GameRoomService
{
    private GameRepository $games;
    private MatchmakingRepository $queue;

    public function __construct(PDO $db)
    {
        $this->games = new GameRepository($db);
        $this->queue = new MatchmakingRepository($db);
    }

    public function clearExpired(): void
    {
        $this->games->deleteExpiredRooms();
    }

    public function checkAvailable(
        string $mode,
        ?int $userId,
        ?string $guestKey,
        ?string $exceptId = null,
        bool $includeWaiting = false
    ): void {
        if ($this->games->hasActive($mode, $userId, $guestKey, $exceptId, $includeWaiting)) {
            throw new ApiException(
                409,
                'У вас уже есть активная партия в этом режиме. Откройте её, чтобы продолжить.'
            );
        }
    }

    // Вызывается внутри транзакции создания или подбора партии.
    public function preparePlayer(
        string $mode,
        ?int $userId,
        ?string $guestKey,
        ?string $exceptId = null,
        bool $includeWaiting = false
    ): void {
        $this->checkAvailable($mode, $userId, $guestKey, $exceptId, $includeWaiting);
        $this->games->deleteWaiting($userId, $guestKey, $exceptId);
        if ($userId !== null) $this->queue->delete($userId, true);
    }
}
