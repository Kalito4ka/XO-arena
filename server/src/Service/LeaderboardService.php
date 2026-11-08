<?php

// сервис рейтинга: получает статистику из бд и возвращает подготовленный список игроков

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;
use App\Repository\UserStatsRepository;

require_once __DIR__ . '/../Repository/UserStatsRepository.php';

final class LeaderboardService
{
    private UserStatsRepository $statistics;

    public function __construct(PDO $db)
    {
        $this->statistics = new UserStatsRepository($db);
    }

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

        $items = [];

        foreach ($this->statistics->leaderboard($sort, $minimumGames) as $index => $row) {
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
