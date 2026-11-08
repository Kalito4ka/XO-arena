<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use App\Repository\GameRepository;
use App\Repository\MatchmakingRepository;
use PDO;
use Throwable;

require_once __DIR__ . '/GameRoomService.php';
require_once __DIR__ . '/../Repository/GameRepository.php';
require_once __DIR__ . '/../Repository/MatchmakingRepository.php';

final class MatchmakingService
{
    private GameRepository $games;
    private MatchmakingRepository $queue;

    public function __construct(private PDO $db)
    {
        $this->games = new GameRepository($db);
        $this->queue = new MatchmakingRepository($db);
    }

    public function search(int $userId, string $mode): array
    {
        if (!in_array($mode, ['classic', 'ultimate', 'gobblers'], true)) {
            throw new ApiException(422, 'Неизвестный режим игры.');
        }

        return $this->transaction(function () use ($userId, $mode): array {
            $existing = $this->queue->findMatched($userId, $mode);
            if ($existing !== null) {
                return ['status' => 'matched', 'gameId' => $existing->gameId];
            }

            $rooms = new GameRoomService($this->db);
            $rooms->checkAvailable($mode, $userId, null);
            $this->queue->touch($userId, $mode);
            $opponent = $this->queue->findOpponent($userId, $mode);
            if ($opponent === null) return ['status' => 'waiting'];

            $rooms->preparePlayer($mode, $userId, null);
            $rooms->preparePlayer($mode, $opponent->userId, null);
            $game = $this->games->createMatched(
                bin2hex(random_bytes(16)), $mode, $opponent->userId, $userId
            );
            $this->queue->matchPlayers($opponent->userId, $userId, $mode, $game->id);

            return ['status' => 'matched', 'gameId' => $game->id];
        });
    }

    public function cancel(int $userId): array
    {
        return $this->transaction(function () use ($userId): array {
            $entry = $this->queue->find($userId);
            if ($entry !== null && $entry->isMatched()) {
                return ['status' => 'matched', 'gameId' => $entry->gameId];
            }
            $this->queue->delete($userId);

            return ['status' => 'cancelled'];
        });
    }

    private function transaction(callable $action): array
    {
        $this->db->beginTransaction();
        try {
            $this->games->lockPlayers();
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
