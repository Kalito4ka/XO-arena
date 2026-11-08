<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Entity\UserStats;
use App\Repository\GameRepository;
use App\Repository\UserStatsRepository;
use LogicException;
use PDO;

require_once __DIR__ . '/../Repository/GameRepository.php';
require_once __DIR__ . '/../Repository/UserStatsRepository.php';

final class GameResultService
{
    private GameRepository $games;
    private UserStatsRepository $statistics;

    public function __construct(private PDO $db)
    {
        $this->games = new GameRepository($db);
        $this->statistics = new UserStatsRepository($db);
    }

    // Результат и статистика сохраняются в одной транзакции только один раз.
    public function record(Game $game, array $state): Game
    {
        if ($state['status'] === 'playing') return $game;
        if (!$this->db->inTransaction()) {
            throw new LogicException('Учёт результата должен выполняться в транзакции');
        }

        $updated = $this->games->recordResult($game->id, $state['status'], $state['winner']);
        if ($updated === null) return $game;

        $players = [];
        foreach (['X', 'O'] as $symbol) {
            $userId = $updated->userIdFor($symbol);
            if ($userId !== null) $players[] = ['id' => $userId, 'symbol' => $symbol];
        }
        usort($players, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        foreach ($players as $player) {
            $isDraw = $state['status'] === 'draw';
            $isWinner = $state['status'] === 'won' && $state['winner'] === $player['symbol'];
            $this->statistics->increment(new UserStats(
                $player['id'], $updated->mode,
                (int) $isWinner, (int) (!$isDraw && !$isWinner), (int) $isDraw
            ));
        }

        return $updated;
    }

    public function stats(int $userId, string $mode = 'classic'): array
    {
        $stats = $this->statistics->find($userId, $mode);

        return [
            'mode' => $stats->mode,
            'played' => $stats->played(),
            'wins' => $stats->wins,
            'losses' => $stats->losses,
            'draws' => $stats->draws,
            'winRate' => $stats->played() === 0 ? 0 : $stats->winRate(),
        ];
    }
}
