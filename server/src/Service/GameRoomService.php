<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;

final class GameRoomService
{
    public function __construct(private PDO $db) {}

    // удаляет комнаты, в которых соперник не появился за три минуты
    public function clearExpired(): void
    {
        $this->db->exec(
            "DELETE FROM games
             WHERE status = 'playing'
               AND o_token_hash IS NULL
               AND created_at <= clock_timestamp() - INTERVAL '3 minutes'"
        );
    }

    // не позволяет игроку начать вторую партию того же режима
    public function checkAvailable(
        string $mode,
        ?int $userId,
        ?string $guestKey,
        ?string $exceptId = null,
        bool $includeWaiting = false
    ): void {
        if ($userId === null && $guestKey === null) {
            return;
        }

        $owner = $userId !== null
            ? '(x_user_id = :x_owner OR o_user_id = :o_owner)'
            : '(x_guest_key = :x_owner OR o_guest_key = :o_owner)';
        $sql = "SELECT id FROM games
                WHERE $owner
                  AND mode = :mode
                  AND status = 'playing'
                  AND id <> :except_id";

        $sql .= $includeWaiting
            ? " AND (o_token_hash IS NOT NULL
                     OR created_at > clock_timestamp() - INTERVAL '3 minutes')"
            : ' AND o_token_hash IS NOT NULL';

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute([
            'x_owner' => $userId ?? $guestKey,
            'o_owner' => $userId ?? $guestKey,
            'mode' => $mode,
            'except_id' => $exceptId ?? '',
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new ApiException(
                409,
                'У вас уже есть активная партия в этом режиме. Откройте её, чтобы продолжить.'
            );
        }
    }

    // освобождает прежнюю комнату ожидания перед новой игрой
    public function preparePlayer(
        string $mode,
        ?int $userId,
        ?string $guestKey,
        ?string $exceptId = null,
        bool $includeWaiting = false
    ): void {
        $this->checkAvailable($mode, $userId, $guestKey, $exceptId, $includeWaiting);

        if ($userId === null && $guestKey === null) {
            return;
        }

        $owner = $userId !== null ? 'x_user_id' : 'x_guest_key';
        $statement = $this->db->prepare(
            "DELETE FROM games
             WHERE $owner = :owner
               AND status = 'playing'
               AND o_token_hash IS NULL
               AND id <> :except_id"
        );
        $statement->execute([
            'owner' => $userId ?? $guestKey,
            'except_id' => $exceptId ?? '',
        ]);

        if ($userId !== null) {
            $statement = $this->db->prepare(
                'DELETE FROM matchmaking_queue
                 WHERE user_id = :user_id AND game_id IS NULL'
            );
            $statement->execute(['user_id' => $userId]);
        }
    }
}
