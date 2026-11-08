<?php

// сервис рейтинга: получает статистику из бд и возвращает подготовленный список игроков

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;

final class LeaderboardService
{
    public function __construct(private PDO $db) {}

    // получает до 25 лидеров с минимум 10 завершёнными партиями
    public function get(string $sort): array
    {
        if (!in_array($sort, ['wins', 'played', 'winRate'], true)) {
            throw new ApiException(
                422,
                'Допустимая сортировка: wins, played или winRate.'
            );
        }

        $minimumGames = 10;

        $orderBy = match ($sort) {
            'wins' => 'wins DESC, win_rate DESC, played DESC, user_id ASC',
            'played' => 'played DESC, wins DESC, user_id ASC',
            'winRate' => 'win_rate DESC, played DESC, user_id ASC',
        };

        $sql = "
            WITH ranking AS (
                SELECT u.id AS user_id,
                       u.nickname,
                       SUM(s.wins) AS wins,
                       SUM(s.losses) AS losses,
                       SUM(s.draws) AS draws,
                       SUM(s.wins + s.losses + s.draws) AS played,
                       100.0 * SUM(s.wins) / NULLIF(
                           SUM(s.wins + s.losses + s.draws),
                           0
                       ) AS win_rate
                FROM user_stats s
                JOIN users u ON u.id = s.user_id
                GROUP BY u.id, u.nickname
            )
            SELECT *
            FROM ranking
            WHERE played >= :minimum_games
            ORDER BY $orderBy
            LIMIT 25
        ";

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':minimum_games', $minimumGames, PDO::PARAM_INT);
        $statement->execute();

        $items = [];

        foreach ($statement->fetchAll() as $index => $row) {
            $items[] = [
                'position' => $index + 1,
                'userId' => (int) $row['user_id'],
                'nickname' => $row['nickname'],
                'played' => (int) $row['played'],
                'wins' => (int) $row['wins'],
                'losses' => (int) $row['losses'],
                'draws' => (int) $row['draws'],
                'winRate' => round((float) $row['win_rate'], 2),
            ];
        }

        return [
            'mode' => 'all',
            'sort' => $sort,
            'minimumGames' => $minimumGames,
            'items' => $items,
        ];
    }
}
