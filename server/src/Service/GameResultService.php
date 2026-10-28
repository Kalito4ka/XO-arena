<?php

// сохраняет результат завершенной партии и ведет статистику аккаунтов
// победы, поражения, ничьи и процент побед

declare(strict_types=1);

namespace App\Service;

use LogicException;
use PDO;

final class GameResultService
{
    public function __construct(private PDO $db){}

    // записывает результат партии и увеличивает счётчики участников
    public function record(
        array $row,
        array $state,
    ): array {
        if ($state['status'] === 'playing') {
            return $row;
        }

        if (!$this->db->inTransaction()) {
            throw new LogicException(
                'Учёт результата должен выполняться в транзакции'
            );
        }

        $statement = $this->db->prepare(
            'UPDATE games
             SET status = :status,
                 winner = :winner,
                 finished_at = clock_timestamp(),
                 stats_recorded_at = clock_timestamp()
             WHERE id = :id
               AND stats_recorded_at IS NULL
             RETURNING *'
        );

        $statement->execute([
            'status' => $state['status'],
            'winner' => $state['winner'],
            'id' => $row['id'],
        ]);

        $updated = $statement->fetch();

        if ($updated === false) {
            return $row;
        }

        $players = [];

        foreach (['X' => 'x_user_id', 'O' => 'o_user_id'] as $symbol => $column) {
            if ($updated[$column] !== null) {
                $players[] = [
                    'id' => (int) $updated[$column],
                    'symbol' => $symbol,
                ];
            }
        }

        usort(
            $players,
            static fn (array $a, array $b): int =>
                $a['id'] <=> $b['id']
        );

        foreach ($players as $player) {
            $isDraw = $state['status'] === 'draw';

            $isWinner = (
                $state['status'] === 'won'
                && $state['winner'] === $player['symbol']
            );

            $statement = $this->db->prepare(
                'INSERT INTO user_stats (
                    user_id, mode, wins, losses, draws
                 )
                 VALUES (
                    :user_id, :mode, :wins, :losses, :draws
                 )
                 ON CONFLICT (user_id, mode)
                 DO UPDATE SET
                    wins = user_stats.wins + EXCLUDED.wins,
                    losses = user_stats.losses + EXCLUDED.losses,
                    draws = user_stats.draws + EXCLUDED.draws'
            );

            $statement->execute([
                'user_id' => $player['id'],
                'mode' => $updated['mode'],
                'wins' => (int) $isWinner,
                'losses' => (int) (!$isDraw && !$isWinner),
                'draws' => (int) $isDraw,
            ]);
        }

        return $updated;
    }

    // получает статистику аккаунта в определенном режиме
    public function stats(
        int $userId,
        string $mode = 'classic'
    ): array {
        $statement = $this->db->prepare(
            'SELECT wins, losses, draws
             FROM user_stats
             WHERE user_id = :user_id
               AND mode = :mode'
        );

        $statement->execute([
            'user_id' => $userId,
            'mode' => $mode,
        ]);

        $row = $statement->fetch();

        $wins = (int) ($row['wins'] ?? 0);
        $losses = (int) ($row['losses'] ?? 0);
        $draws = (int) ($row['draws'] ?? 0);

        $played = $wins + $losses + $draws;

        return [
            'mode' => $mode,
            'played' => $played,
            'wins' => $wins,
            'losses' => $losses,
            'draws' => $draws,
            'winRate' => $played === 0
                ? 0
                : round($wins * 100 / $played, 2),
        ];
    }

}



