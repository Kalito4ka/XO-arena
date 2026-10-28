<?php

// сервис рейтинга: получает статистику из бд и возвращает подготовленный список игроков

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;

final class LeaderboardService
{
    public function __construct(private PDO $db) {}

        // получает до 10 лидеров с минимум 10 завершёнными партиями
    public function get(string $sort): array
    {
        if (!in_array($sort, ['winRate', 'played'], true)) {
            throw new ApiException(
                422,
                'Допустимая сортировка: winRate или played.'
            );
        }

        $minimumGames = 10;

        $orderBy = $sort === 'winRate'
            ? 'win_rate DESC, played DESC, user_id ASC'
            : 'played DESC, win_rate DESC, user_id ASC';

        $sql = "
            WITH ranking AS (
                SELECT u.id AS user_id,
                       u.nickname,
                       s.wins,
                       s.losses,
                       s.draws,
                       s.wins + s.losses + s.draws AS played,
                       100.0 * s.wins / NULLIF(
                           s.wins + s.losses + s.draws,
                           0
                       ) AS win_rate
                FROM user_stats s
                JOIN users u ON u.id = s.user_id
                WHERE s.mode = :mode
            )
            SELECT *
            FROM ranking
            WHERE played >= :minimum_games
            ORDER BY $orderBy
            LIMIT 10
        ";

        $statement = $this->db->prepare($sql);
        $statement->bindValue(':mode', 'classic');
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
            'mode' => 'classic',
            'sort' => $sort,
            'minimumGames' => $minimumGames,
            'items' => $items,
        ];
    }
}
