<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MatchmakingEntry;
use PDO;

require_once __DIR__ . '/../Entity/MatchmakingEntry.php';

final class MatchmakingRepository
{
    public function __construct(private PDO $db) {}

    public function find(int $userId): ?MatchmakingEntry
    {
        $statement = $this->db->prepare('SELECT * FROM matchmaking_queue WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findMatched(int $userId, string $mode): ?MatchmakingEntry
    {
        $statement = $this->db->prepare(
            "SELECT q.* FROM matchmaking_queue q
             JOIN games g ON g.id = q.game_id
             WHERE q.user_id = :user_id AND g.status = 'playing'
               AND g.mode = :mode AND q.mode = g.mode"
        );
        $statement->execute(['user_id' => $userId, 'mode' => $mode]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function touch(int $userId, string $mode): MatchmakingEntry
    {
        $statement = $this->db->prepare(
            "INSERT INTO matchmaking_queue (user_id, mode)
             VALUES (:user_id, :mode)
             ON CONFLICT (user_id) DO UPDATE
             SET queued_at = CASE
                     WHEN matchmaking_queue.mode <> EXCLUDED.mode
                       OR matchmaking_queue.game_id IS NOT NULL
                       OR matchmaking_queue.seen_at <= clock_timestamp() - INTERVAL '30 seconds'
                     THEN clock_timestamp()
                     ELSE matchmaking_queue.queued_at
                 END,
                 mode = EXCLUDED.mode, game_id = NULL, seen_at = clock_timestamp()
             RETURNING *"
        );
        $statement->execute(['user_id' => $userId, 'mode' => $mode]);

        return $this->hydrate($statement->fetch());
    }

    // Вызывается внутри транзакции под общей блокировкой подбора и комнат.
    public function findOpponent(int $userId, string $mode): ?MatchmakingEntry
    {
        $statement = $this->db->prepare(
            "SELECT * FROM matchmaking_queue
             WHERE mode = :mode AND user_id <> :user_id AND game_id IS NULL
               AND seen_at > clock_timestamp() - INTERVAL '30 seconds'
               AND NOT EXISTS (
                   SELECT 1 FROM games g
                   WHERE g.status = 'playing' AND g.o_token_hash IS NOT NULL
                     AND g.mode = matchmaking_queue.mode
                     AND (g.x_user_id = matchmaking_queue.user_id
                          OR g.o_user_id = matchmaking_queue.user_id)
               )
             ORDER BY queued_at, user_id LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['mode' => $mode, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function matchPlayers(int $firstId, int $secondId, string $mode, string $gameId): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO matchmaking_queue (user_id, mode, game_id)
             VALUES (:first_user, :first_mode, :first_game),
                    (:second_user, :second_mode, :second_game)
             ON CONFLICT (user_id) DO UPDATE SET game_id = EXCLUDED.game_id'
        );
        $statement->execute([
            'first_user' => $firstId, 'second_user' => $secondId,
            'first_mode' => $mode, 'second_mode' => $mode,
            'first_game' => $gameId, 'second_game' => $gameId,
        ]);
    }

    public function delete(int $userId, bool $waitingOnly = false): void
    {
        $sql = 'DELETE FROM matchmaking_queue WHERE user_id = :user_id';
        if ($waitingOnly) $sql .= ' AND game_id IS NULL';
        $statement = $this->db->prepare($sql);
        $statement->execute(['user_id' => $userId]);
    }

    private function hydrate(array $row): MatchmakingEntry
    {
        return new MatchmakingEntry(
            (int) $row['user_id'], $row['mode'], $row['game_id'], $row['queued_at'], $row['seen_at']
        );
    }
}
