<?php

declare(strict_types=1);

namespace App\Game;

use DomainException;
use InvalidArgumentException;

final class ClassicGame
{
    private const WINNING_LINES = [
        [0, 1, 2],
        [3, 4, 5],
        [6, 7, 8],

        [0, 3, 6],
        [1, 4, 7],
        [2, 5, 8],

        [0, 4, 8],
        [2, 4, 6],
    ];

    private array $board = [
        null, null, null,
        null, null, null,
        null, null, null,
    ];

    private ?string $currentPlayer = 'X';

    private string $status = 'playing';

    private ?string $winner = null;

    private ?array $winningLine = null;

    public function move(string $symbol, int $cell): void
    {
        if (!in_array($symbol, ['X', 'O'], true)) {
            throw new InvalidArgumentException(
                'Допустимы только символы X и O.'
            );
        }

        if ($cell < 0 || $cell > 8) {
            throw new InvalidArgumentException(
                'Номер клетки должен быть от 0 до 8.'
            );
        }

        if ($this->status !== 'playing') {
            throw new DomainException(
                'Партия уже завершена.'
            );
        }

        if ($symbol !== $this->currentPlayer) {
            throw new DomainException(
                'Сейчас очередь другого игрока.'
            );
        }

        if ($this->board[$cell] !== null) {
            throw new DomainException(
                'Эта клетка уже занята.'
            );
        }

        $this->board[$cell] = $symbol;

        foreach (self::WINNING_LINES as $line) {
            [$first, $second, $third] = $line;

            if (
                $this->board[$first] === $symbol
                && $this->board[$second] === $symbol
                && $this->board[$third] === $symbol
            ) {
                $this->status = 'won';
                $this->winner = $symbol;
                $this->winningLine = $line;
                $this->currentPlayer = null;

                return;
            }
        }

        if (!in_array(null, $this->board, true)) {
            $this->status = 'draw';
            $this->currentPlayer = null;

            return;
        }

        $this->currentPlayer = $symbol === 'X' ? 'O' : 'X';
    }

    public function getState(): array
    {
        return [
            'mode' => 'classic',
            'board' => $this->board,
            'currentPlayer' => $this->currentPlayer,
            'status' => $this->status,
            'winner' => $this->winner,
            'winningLine' => $this->winningLine,
        ];
    }
}