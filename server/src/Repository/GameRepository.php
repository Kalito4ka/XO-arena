<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use PDO;
use RuntimeException;

require_once __DIR__ . '/../Entity/Game.php';

final class GameRepository
{
    private const TURN_DURATION_SQL = "CASE %s
        WHEN 'classic' THEN INTERVAL '15 seconds'
        WHEN 'ultimate' THEN INTERVAL '45 seconds'
        WHEN 'gobblers' THEN INTERVAL '30 seconds'
    END";

    public function __construct(private PDO $db) {}

    public function lockPlayers(): void
    {
        $this->db->query('SELECT pg_advisory_xact_lock(5819)');
    }

    public function find(string $id, bool $lock = false, bool $visibleOnly = true): ?Game
    {
        $sql = 'SELECT * FROM games WHERE id = :id';
        if ($visibleOnly) {
            $sql .= " AND (o_token_hash IS NOT NULL
                          OR created_at > clock_timestamp() - INTERVAL '3 minutes')";
        }
        if ($lock) $sql .= ' FOR UPDATE';
        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function createWaiting(
        string $id, string $mode, string $tokenHash, ?int $userId, ?string $guestKey
    ): Game {
        return $this->insert($id, $mode, $userId, null, $tokenHash, null, $guestKey, null);
    }

    public function createMatched(string $id, string $mode, int $xUserId, int $oUserId): Game
    {
        return $this->insert(
            $id, $mode, $xUserId, $oUserId,
            bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), null, null
        );
    }

    public function createRematch(string $id, Game $previous): Game
    {
        return $this->insert(
            $id, $previous->mode, $previous->xUserId, $previous->oUserId,
            $previous->tokenHashFor('X'), $previous->tokenHashFor('O'),
            $previous->guestKeyFor('X'), $previous->guestKeyFor('O')
        );
    }

    private function insert(
        string $id, string $mode, ?int $xUserId, ?int $oUserId,
        string $xTokenHash, ?string $oTokenHash, ?string $xGuestKey, ?string $oGuestKey
    ): Game {
        $statement = $this->db->prepare(
            'INSERT INTO games (
                 id, mode, x_user_id, o_user_id, x_token_hash, o_token_hash,
                 x_guest_key, o_guest_key, updated_at
             ) VALUES (
                 :id, :mode, :x_user, :o_user, :x_token, :o_token,
                 :x_guest, :o_guest, clock_timestamp()
             ) RETURNING *'
        );
        $statement->execute([
            'id' => $id, 'mode' => $mode, 'x_user' => $xUserId, 'o_user' => $oUserId,
            'x_token' => $xTokenHash, 'o_token' => $oTokenHash,
            'x_guest' => $xGuestKey, 'o_guest' => $oGuestKey,
        ]);

        return $this->hydrate($statement->fetch());
    }

    public function join(string $id, string $tokenHash, ?int $userId, ?string $guestKey): Game
    {
        $statement = $this->db->prepare(
            'UPDATE games SET o_token_hash = :token_hash, o_user_id = :user_id,
                 o_guest_key = :guest_key, updated_at = clock_timestamp()
             WHERE id = :id RETURNING *'
        );
        $statement->execute([
            'id' => $id, 'token_hash' => $tokenHash, 'user_id' => $userId, 'guest_key' => $guestKey,
        ]);

        return $this->hydrate($statement->fetch());
    }

    public function acceptRematch(string $id, string $symbol): ?Game
    {
        $column = match ($symbol) {
            'X' => 'rematch_x',
            'O' => 'rematch_o',
            default => throw new \InvalidArgumentException('Допустимы только X и O.'),
        };
        $statement = $this->db->prepare(
            "UPDATE games SET $column = TRUE
             WHERE id = :id AND finished_at + INTERVAL '30 seconds' > clock_timestamp()
             RETURNING *"
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function linkRematch(string $id, string $nextId): Game
    {
        $statement = $this->db->prepare(
            'UPDATE games SET rematch_id = :next_id WHERE id = :id RETURNING *'
        );
        $statement->execute(['id' => $id, 'next_id' => $nextId]);

        return $this->hydrate($statement->fetch());
    }

    public function saveMoves(string $id, array $moves): Game
    {
        $statement = $this->db->prepare(
            'UPDATE games SET moves = CAST(:moves AS jsonb), updated_at = clock_timestamp()
             WHERE id = :id RETURNING *'
        );
        $statement->execute(['id' => $id, 'moves' => json_encode($moves, JSON_THROW_ON_ERROR)]);

        return $this->hydrate($statement->fetch());
    }

    // NULL означает, что результат уже был учтён.
    public function recordResult(string $id, string $status, ?string $winner): ?Game
    {
        $statement = $this->db->prepare(
            'UPDATE games SET status = :status, winner = :winner,
                 finished_at = clock_timestamp(), stats_recorded_at = clock_timestamp()
             WHERE id = :id AND stats_recorded_at IS NULL RETURNING *'
        );
        $statement->execute(['id' => $id, 'status' => $status, 'winner' => $winner]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function remainingRematchSeconds(Game $game): float
    {
        if ($game->finishedAt === null) return 0;
        $statement = $this->db->prepare(
            "SELECT GREATEST(0, EXTRACT(EPOCH FROM (
                CAST(:finished_at AS timestamptz) + INTERVAL '30 seconds' - clock_timestamp()
            )))"
        );
        $statement->execute(['finished_at' => $game->finishedAt]);

        return (float) $statement->fetchColumn();
    }

    public function remainingTurnSeconds(Game $game): float
    {
        $duration = sprintf(self::TURN_DURATION_SQL, 'CAST(:mode AS text)');
        $statement = $this->db->prepare(
            "SELECT GREATEST(0, EXTRACT(EPOCH FROM (
                CAST(:updated_at AS timestamptz) + $duration - clock_timestamp()
            )))"
        );
        $statement->execute(['updated_at' => $game->updatedAt, 'mode' => $game->mode]);

        return (float) $statement->fetchColumn();
    }

    public function isTurnExpired(string $id): bool
    {
        $duration = sprintf(self::TURN_DURATION_SQL, 'mode');
        $statement = $this->db->prepare(
            "SELECT 1 FROM games WHERE id = :id
             AND updated_at + $duration <= clock_timestamp()"
        );
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    public function expiredTurnIds(): array
    {
        $duration = sprintf(self::TURN_DURATION_SQL, 'mode');
        $statement = $this->db->query(
            "SELECT id FROM games
             WHERE mode IN ('classic', 'ultimate', 'gobblers')
               AND status = 'playing' AND o_token_hash IS NOT NULL
               AND updated_at + $duration <= clock_timestamp()
             ORDER BY updated_at, id LIMIT 100"
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteExpiredRooms(): void
    {
        $this->db->exec(
            "DELETE FROM games WHERE status = 'playing' AND o_token_hash IS NULL
             AND created_at <= clock_timestamp() - INTERVAL '3 minutes'"
        );
    }

    public function hasActive(
        string $mode, ?int $userId, ?string $guestKey, ?string $exceptId, bool $includeWaiting
    ): bool {
        if ($userId === null && $guestKey === null) return false;
        $owner = $userId !== null
            ? '(x_user_id = :x_owner OR o_user_id = :o_owner)'
            : '(x_guest_key = :x_owner OR o_guest_key = :o_owner)';
        $sql = "SELECT id FROM games WHERE $owner AND mode = :mode
                AND status = 'playing' AND id <> :except_id";
        $sql .= $includeWaiting
            ? " AND (o_token_hash IS NOT NULL OR created_at > clock_timestamp() - INTERVAL '3 minutes')"
            : ' AND o_token_hash IS NOT NULL';
        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute([
            'x_owner' => $userId ?? $guestKey, 'o_owner' => $userId ?? $guestKey,
            'mode' => $mode, 'except_id' => $exceptId ?? '',
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function deleteWaiting(?int $userId, ?string $guestKey, ?string $exceptId): void
    {
        if ($userId === null && $guestKey === null) return;
        $owner = $userId !== null ? 'x_user_id' : 'x_guest_key';
        $statement = $this->db->prepare(
            "DELETE FROM games WHERE $owner = :owner AND status = 'playing'
             AND o_token_hash IS NULL AND id <> :except_id"
        );
        $statement->execute(['owner' => $userId ?? $guestKey, 'except_id' => $exceptId ?? '']);
    }

    // История содержит имена из JOIN и результат для зрителя, поэтому возвращает проекцию.
    public function history(int $userId, bool $finished): array
    {
        $sql = "SELECT g.id, g.mode, g.status, g.winner, g.x_user_id,
                       g.created_at, g.finished_at,
                       CASE WHEN g.o_token_hash IS NULL THEN 'waiting' ELSE 'playing' END AS progress,
                       x.nickname AS x_nickname, o.nickname AS o_nickname
                FROM games g
                LEFT JOIN users x ON x.id = g.x_user_id
                LEFT JOIN users o ON o.id = g.o_user_id
                WHERE (g.x_user_id = :x_user_id OR g.o_user_id = :o_user_id)";
        $sql .= $finished
            ? " AND g.status IN ('won', 'draw') ORDER BY g.finished_at DESC, g.id DESC LIMIT 10"
            : " AND g.status = 'playing'
                AND (g.o_token_hash IS NOT NULL OR g.created_at > clock_timestamp() - INTERVAL '3 minutes')
                ORDER BY g.created_at DESC, g.id DESC";
        $statement = $this->db->prepare($sql);
        $statement->execute(['x_user_id' => $userId, 'o_user_id' => $userId]);

        return $statement->fetchAll();
    }

    private function hydrate(array $row): Game
    {
        $moves = json_decode($row['moves'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($moves) || !array_is_list($moves)) {
            throw new RuntimeException('В базе хранится некорректная история партии.');
        }

        return new Game(
            $row['id'], $row['mode'], $moves, $row['status'], $row['winner'],
            $row['x_user_id'] === null ? null : (int) $row['x_user_id'],
            $row['o_user_id'] === null ? null : (int) $row['o_user_id'],
            $row['x_token_hash'], $row['o_token_hash'], $row['x_guest_key'], $row['o_guest_key'],
            $row['created_at'], $row['updated_at'], $row['finished_at'], $row['stats_recorded_at'],
            $row['end_reason'], (bool) $row['rematch_x'], (bool) $row['rematch_o'], $row['rematch_id']
        );
    }
}
