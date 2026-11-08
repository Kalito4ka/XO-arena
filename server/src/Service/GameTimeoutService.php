<?php

// сервис проверки времени

declare(strict_types=1);

namespace App\Service;

use App\Game\ClassicGame;
use App\Game\UltimateGame;
use App\Game\GobblersGame;

use App\Entity\Game;
use App\Repository\GameRepository;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/GameRoomService.php';
require_once __DIR__ . '/../Repository/GameRepository.php';

final class GameTimeoutService
{
    private GameRepository $games;

    public function __construct(private PDO $db)
    {
        $this->games = new GameRepository($db);
    }

    // делает случайный допустимый ход после окончания времени
    public function expireLocked(Game $row): Game
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException(
                'Проверка времени должна выполняться в транзакции.'
            );
        }

        if (
            !in_array($row->mode, ['classic', 'ultimate', 'gobblers'], true)
            || $row->status !== 'playing'
            || $row->isWaiting()
        ) {
            return $row;
        }

        if (!$this->games->isTurnExpired($row->id)) return $row;
        $moves = $row->moves;

        $game = match ($row->mode) {
            'classic' => new ClassicGame(),
            'ultimate' => new UltimateGame(),
            'gobblers' => new GobblersGame(),
        };

        foreach ($moves as $index => $move) {
            $symbol = $index % 2 === 0 ? 'X' : 'O';

            if ($row->mode === 'ultimate') {
                $game->move(
                    $symbol,
                    $move['board'],
                    $move['cell']
                );
            } elseif ($row->mode === 'gobblers') {
                $game->move(
                    $symbol,
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

        if ($row->mode === 'ultimate') {
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
        } elseif ($row->mode === 'gobblers') {
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

        if ($row->mode === 'ultimate') {
            $game->move(
                $state['currentPlayer'],
                $move['board'],
                $move['cell']
            );
        } elseif ($row->mode === 'gobblers') {
            $game->move(
                $state['currentPlayer'],
                $move['cell'],
                $move['size'],
                $move['from']
            );
        } else {
            $game->move($state['currentPlayer'], $move);
        }

        $moves[] = $move;

        $row = $this->games->saveMoves($row->id, $moves);

        return (new GameResultService($this->db))->record(
            $row,
            $game->getState()
        );
    }

    // обрабатывает просроченные партии без запросов из браузера
    public function expireBatch(): int
    {
        (new GameRoomService($this->db))->clearExpired();

        $ids = $this->games->expiredTurnIds();
        $count = 0;

        foreach ($ids as $id) {
            $this->db->beginTransaction();

            try {
                $row = $this->games->find($id, true, false);
                if ($row !== null) {
                    $updated = $this->expireLocked($row);

                    if ($row->moves !== $updated->moves) {
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