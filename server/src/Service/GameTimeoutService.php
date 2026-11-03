<?php

// сервис проверки времени

declare(strict_types=1);

namespace App\Service;

use App\Game\ClassicGame;
use App\Game\UltimateGame;
use App\Game\GobblersGame;

use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class GameTimeoutService
{
    public function __construct(private PDO $db)
    {
    }

    // делает случайный допустимый ход после окончания времени
    public function expireLocked(array $row): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException(
                'Проверка времени должна выполняться в транзакции.'
            );
        }

        if (
            !in_array($row['mode'], ['classic', 'ultimate', 'gobblers'], true)
            || $row['status'] !== 'playing'
            || $row['o_token_hash'] === null
        ) {
            return $row;
        }

        $statement = $this->db->prepare(
            "SELECT 1
             FROM games
             WHERE id = :id
               AND updated_at + CASE mode
                   WHEN 'classic' THEN INTERVAL '15 seconds'

                   WHEN 'gobblers' THEN INTERVAL '30 seconds'
                   ELSE INTERVAL '45 seconds'
               END <= clock_timestamp()"
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

        $game = match ($row['mode']) {
            'classic' => new ClassicGame(),
            'ultimate' => new UltimateGame(),
            'gobblers' => new GobblersGame(),
        };

        foreach ($moves as $index => $move) {
            $symbol = $index % 2 === 0 ? 'X' : 'O';

            if ($row['mode'] === 'ultimate') {
                $game->move(
                    $symbol,
                    $move['board'],
                    $move['cell']
                );
            } elseif ($row['mode'] === 'gobblers') {
                $game->move(
                    $state['currentPlayer'],
                    $move['cell'],
                    $move['size'],
                    $move['from']
                );
            } else {
                $game->move($symbol, $move);
            }
        }

        $state = $game->getState();
        $choices = [];

        if ($state['status'] !== 'playing') {
            throw new RuntimeException(
                'Состояние партии не соответствует сохранённым ходам.'
            );
        }

        if ($row['mode'] === 'ultimate') {
            foreach ($state['boards'] as $board => $cells) {
                if (
                    $state['boardStatuses'][$board] !== 'playing'
                    || (
                        $state['requiredBoard'] !== null
                        && $state['requiredBoard'] !== $board
                    )
                ) {
                    continue;
                }

                foreach ($cells as $cell => $value) {
                    if ($value === null) {
                        $choices[] = [
                            'board' => $board,
                            'cell' => $cell,
                        ];
                    }
                }
            }
        } elseif ($row['mode'] === 'gobblers') {
            $choices = $game->getLegalMoves();
        } else {
            foreach ($state['board'] as $cell => $value) {
                if ($value === null) {
                    $choices[] = $cell;
                }
            }
        }

        if ($choices === []) {
            throw new RuntimeException(
                'В активной партии нет допустимых ходов.'
            );
        }

        $move = $choices[random_int(0, count($choices) - 1)];

        if ($row['mode'] === 'ultimate') {
            $game->move(
                $state['currentPlayer'],
                $move['board'],
                $move['cell']
            );
        } elseif ($row['mode'] === 'gobblers') {
            $game->move(
                $symbol,
                $move['cell'],
                $move['size'],
                $move['from']
            );
        } else {
            $game->move($state['currentPlayer'], $move);
        }

        $moves[] = $move;

        $statement = $this->db->prepare(
            'UPDATE games
             SET moves = CAST(:moves AS jsonb),
                 updated_at = clock_timestamp()
             WHERE id = :id
             RETURNING *'
        );

        $statement->execute([
            'moves' => json_encode($moves, JSON_THROW_ON_ERROR),
            'id' => $row['id'],
        ]);

        $row = $statement->fetch();

        return (new GameResultService($this->db))->record(
            $row,
            $game->getState()
        );
    }

    // обрабатывает просроченные партии без запросов из браузера
    public function expireBatch(): int
    {
        $statement = $this->db->query(
            "SELECT id
             FROM games
             WHERE mode IN ('classic', 'ultimate', 'gobblers')
               AND status = 'playing'
               AND o_token_hash IS NOT NULL
               AND updated_at + CASE mode
                   WHEN 'classic' THEN INTERVAL '15 seconds'

                   WHEN 'gobblers' THEN INTERVAL '30 seconds'
                   ELSE INTERVAL '45 seconds'
               END
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

                    if ($row['moves'] !== $updated['moves']) {
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