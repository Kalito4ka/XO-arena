<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;
use Throwable;

require_once __DIR__ . '/GameRoomService.php';

final class MatchmakingService
{
    public function __construct(private PDO $db){}

    public function search(int $userId, string $mode): array
    {
        if (!in_array($mode, ['classic', 'ultimate', 'gobblers'], true)) {
            throw new ApiException(422, 'Неизвестный режим игры.');
        }

        $this->db->beginTransaction();

        try {
            $this->db->query('SELECT pg_advisory_xact_lock(5819)');

            // проверяем, не нашел ли другой игрок соперника раньше
            $statement = $this->db->prepare(
                "SELECT q.game_id
                 FROM matchmaking_queue q
                 JOIN games g ON g.id = q.game_id
                 WHERE q.user_id = :user_id
                   AND g.status = 'playing'"
            );
            $statement->execute(['user_id' => $userId]);
            $existingGame = $statement->fetchColumn();

            if ($existingGame !== false) {
                $this->db->commit();

                return [
                    'status' => 'matched',
                    'gameId' => $existingGame,
                ];
            }

            $rooms = new GameRoomService($this->db);
            $rooms->checkAvailable($mode, $userId, null);

            // если заявки нет, добавляем её
            // иначе, обновляем
            $statement = $this->db->prepare(
                "INSERT INTO matchmaking_queue (user_id, mode)
                 VALUES (:user_id, :mode)
                 ON CONFLICT (user_id) DO UPDATE
                 SET queued_at = CASE
                         WHEN matchmaking_queue.mode <> EXCLUDED.mode
                           OR matchmaking_queue.game_id IS NOT NULL
                           OR matchmaking_queue.seen_at
                               <= clock_timestamp() - INTERVAL '30 seconds'
                         THEN clock_timestamp()
                         ELSE matchmaking_queue.queued_at
                     END,
                     mode = EXCLUDED.mode,
                     game_id = NULL,
                     seen_at = clock_timestamp()"
            );
            $statement->execute([
                'user_id' => $userId,
                'mode' => $mode,
            ]);

            // ищем другого игрока в том же режиме
            $statement = $this->db->prepare(
                "SELECT user_id
                 FROM matchmaking_queue
                 WHERE mode = :mode
                   AND user_id <> :user_id
                   AND game_id IS NULL
                   AND seen_at > clock_timestamp() - INTERVAL '30 seconds'
                   AND NOT EXISTS (
                       SELECT 1 FROM games g
                       WHERE g.status = 'playing'
                         AND g.o_token_hash IS NOT NULL
                         AND g.mode = matchmaking_queue.mode
                         AND (g.x_user_id = matchmaking_queue.user_id
                              OR g.o_user_id = matchmaking_queue.user_id)
                   )
                 ORDER BY queued_at, user_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute([
                'mode' => $mode,
                'user_id' => $userId,
            ]);
            $opponentId = $statement->fetchColumn();

            // соперника нет, сохраняем заявку, чтобы следующий игрок мог найти её
            if ($opponentId === false) {
                $this->db->commit();

                return ['status' => 'waiting'];
            }

            $rooms->preparePlayer($mode, $userId, null);
            $rooms->preparePlayer($mode, (int) $opponentId, null);

            $gameId = bin2hex(random_bytes(16));

            // создаем партию с двумя участниками
            $statement = $this->db->prepare(
                'INSERT INTO games (
                    id, mode,
                    x_user_id, o_user_id,
                    x_token_hash, o_token_hash,
                    updated_at
                 )
                 VALUES (
                    :id, :mode,
                    :x_user_id, :o_user_id,
                    :x_token_hash, :o_token_hash,
                    clock_timestamp()
                 )'
            );
            $statement->execute([
                'id' => $gameId,
                'mode' => $mode,
                'x_user_id' => (int) $opponentId,
                'o_user_id' => $userId,

                'x_token_hash' => bin2hex(random_bytes(32)),
                'o_token_hash' => bin2hex(random_bytes(32)),
            ]);

            $statement = $this->db->prepare(
                'INSERT INTO matchmaking_queue (user_id, mode, game_id)
                 VALUES (:first_user, :first_mode, :first_game),
                        (:second_user, :second_mode, :second_game)
                 ON CONFLICT (user_id) DO UPDATE SET game_id = EXCLUDED.game_id'
            );
            $statement->execute([
                'first_game' => $gameId,
                'second_game' => $gameId,
                'first_mode' => $mode,
                'second_mode' => $mode,
                'first_user' => (int) $opponentId,
                'second_user' => $userId,
            ]);

            // Сохраняем партию и изменения обеих заявок.
            $this->db->commit();

            return [
                'status' => 'matched',
                'gameId' => $gameId,
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    public function cancel(int $userId): array
    {
        $this->db->beginTransaction();

        try {
            $this->db->query('SELECT pg_advisory_xact_lock(5819)');

            $statement = $this->db->prepare(
                'SELECT game_id
                 FROM matchmaking_queue
                 WHERE user_id = :user_id'
            );
            $statement->execute(['user_id' => $userId]);
            $entry = $statement->fetch();

            if ($entry !== false && $entry['game_id'] !== null) {
                $this->db->commit();

                return [
                    'status' => 'matched',
                    'gameId' => $entry['game_id'],
                ];
            }

            $statement = $this->db->prepare(
                'DELETE FROM matchmaking_queue
                 WHERE user_id = :user_id'
            );
            $statement->execute(['user_id' => $userId]);

            $this->db->commit();

            return ['status' => 'cancelled'];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }
}