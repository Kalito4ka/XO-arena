<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserStats;
use PDO;

require_once __DIR__ . '/../Entity/UserStats.php';

final class UserStatsRepository
{
    public function __construct(private PDO $db) {}

    public function find(int $userId, string $mode): UserStats
    {
        $statement = $this->db->prepare(
            'SELECT * FROM user_stats WHERE user_id = :user_id AND mode = :mode'
        );
        $statement->execute(['user_id' => $userId, 'mode' => $mode]);
        $row = $statement->fetch();

        return new UserStats(
            $userId, $mode, (int) ($row['wins'] ?? 0),
            (int) ($row['losses'] ?? 0), (int) ($row['draws'] ?? 0)
        );
    }

    public function increment(UserStats $result): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO user_stats (user_id, mode, wins, losses, draws)
             VALUES (:user_id, :mode, :wins, :losses, :draws)
             ON CONFLICT (user_id, mode) DO UPDATE SET
                 wins = user_stats.wins + EXCLUDED.wins,
                 losses = user_stats.losses + EXCLUDED.losses,
                 draws = user_stats.draws + EXCLUDED.draws'
        );
        $statement->execute([
            'user_id' => $result->userId, 'mode' => $result->mode,
            'wins' => $result->wins, 'losses' => $result->losses, 'draws' => $result->draws,
        ]);
    }

    // Рейтинг — выборка с агрегатами, а не отдельная хранимая сущность.
    public function leaderboard(string $sort, int $minimumGames): array
    {
        $orderBy = match ($sort) {
            'wins' => 'wins DESC, win_rate DESC, played DESC, user_id ASC',
            'played' => 'played DESC, wins DESC, user_id ASC',
            'winRate' => 'win_rate DESC, played DESC, user_id ASC',
            default => throw new \InvalidArgumentException('Неизвестная сортировка.'),
        };
        $statement = $this->db->prepare("
            WITH ranking AS (
                SELECT u.id AS user_id, u.nickname,
                       SUM(s.wins) AS wins, SUM(s.losses) AS losses, SUM(s.draws) AS draws,
                       SUM(s.wins + s.losses + s.draws) AS played,
                       100.0 * SUM(s.wins) / NULLIF(SUM(s.wins + s.losses + s.draws), 0) AS win_rate
                FROM user_stats s JOIN users u ON u.id = s.user_id
                GROUP BY u.id, u.nickname
            )
            SELECT * FROM ranking WHERE played >= :minimum_games
            ORDER BY $orderBy LIMIT 25
        ");
        $statement->bindValue(':minimum_games', $minimumGames, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
