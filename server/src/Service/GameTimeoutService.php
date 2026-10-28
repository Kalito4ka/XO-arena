<?php

// сервис проверки времени

declare(strict_types=1);

namespace App\Service;

use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class GameTimeoutService
{
    public function __construct(private PDO $db)
    {
    }

    // завершает просроченную партию с уже заблокированной записью
    public function expireLocked(array $row): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException(
                'Проверка времени должна выполняться в транзакции.'
            );
        }

        if (
            $row['mode'] !== 'classic'
            || $row['status'] !== 'playing'
            || $row['o_token_hash'] === null
        ) {
            return $row;
        }

        $statement = $this->db->prepare(
            "SELECT 1
             FROM games
             WHERE id = :id
               AND updated_at + INTERVAL '30 seconds'
                   <= clock_timestamp()"
        );

        $statement->execute(['id' => $row['id']]);

        if ($statement->fetchColumn() === false) {
            return $row;
        }

        $moves = json_decode(
            $row['moves'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($moves) || !array_is_list($moves)) {
            throw new RuntimeException(
                'В базе хранится некорректная история партии.'
            );
        }

        $loser = count($moves) % 2 === 0 ? 'X' : 'O';
        $winner = $loser === 'X' ? 'O' : 'X';

        $row = (new GameResultService($this->db))->record(
            $row,
            [
                'status' => 'won',
                'winner' => $winner,
            ]
        );

        $statement = $this->db->prepare(
            "UPDATE games
             SET end_reason = 'timeout'
             WHERE id = :id
             RETURNING *"
        );

        $statement->execute(['id' => $row['id']]);

        return $statement->fetch();
    }

    // обрабатывает просроченные партии без запросов из браузера
    public function expireBatch(): int
    {
        $statement = $this->db->query(
            "SELECT id
             FROM games
             WHERE mode = 'classic'
               AND status = 'playing'
               AND o_token_hash IS NOT NULL
               AND updated_at + INTERVAL '30 seconds'
                   <= clock_timestamp()
             ORDER BY updated_at, id
             LIMIT 100"
        );

        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;

        foreach ($ids as $id) {
            $this->db->beginTransaction();

            try {
                $statement = $this->db->prepare(
                    'SELECT *
                     FROM games
                     WHERE id = :id
                     FOR UPDATE'
                );

                $statement->execute(['id' => $id]);

                $row = $statement->fetch();

                if ($row !== false) {
                    $updated = $this->expireLocked($row);

                    if (
                        $row['status'] === 'playing'
                        && $updated['status'] === 'won'
                        && $updated['end_reason'] === 'timeout'
                    ) {
                        $count++;
                    }
                }

                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                throw $error;
            }
        }

        return $count;
    }
}