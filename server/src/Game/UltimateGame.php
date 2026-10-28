<?php

// класс правил для ультимативных крестиков-ноликов

declare(strict_types=1);

namespace App\Game;

use DomainException;
use InvalidArgumentException;

final class UltimateGame 
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

    private array $boards;
    private array $boardStatuses;
    private ?int $requiredBoard = null;
    private ?string $currentPlayer = 'X';
    private string $status = 'playing';
    private ?string $winner = null;
    private ?array $winningLine = null;

    public function __construct()
    {
        $this->boards = array_fill(
            0,
            9,
            array_fill(0, 9, null),
        );
        $this->boardStatuses = array_fill(0, 9, 'playing');
    }

    public function move(
        string $symbol,
        int $board,
        int $cell
    ): void {
        if (!in_array($symbol, ['X', 'O'], true)) {
            throw new InvalidArgumentException(
                'Допустимы только символы X и O.'
            );
        }

        if ($board < 0 || $board > 8) {
            throw new InvalidArgumentException(
                'Номер поля должен быть от 0 до 8.'
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

        if ($this->currentPlayer !== $symbol){
            throw new DomainException(
                'Сейчас очередь другого игрока.'
            );
        }

        if (
            $this->requiredBoard !== null
            && $board !== $this->requiredBoard
        ) {
            throw new DomainException(
                'Сейчас нужно ходить на поле '
                . $this->requiredBoard
                . '.'
            );
        }

        if (
            $this->boardStatuses[$board] !== 'playing'
        ) {
            throw new DomainException (
                'Это маленькое поле уже завершено.'
            );
        }

        if ($this->boards[$board][$cell] !== null) {
            throw new DomainException (
                'Эта клетка уже занята.'
            );
        }

        // все проверки пройдены => ход разрешен
        $this->boards[$board][$cell] = $symbol;

        if ($this->findWinningLine(
            $this->boards[$board],
            $symbol
        ) !== null) {
            $this->boardStatuses[$board] = $symbol;
        } elseif (!in_array(null, $this->boards[$board], true)) {
            $this->boardStatuses[$board] = 'draw';
        }

        $line = $this->findWinningLine(
            $this->boardStatuses,
            $symbol
        );

        // победа
        if ($line !== null) {
            $this->status = 'won';
            $this->winner = $symbol;
            $this->winningLine = $line;
            $this->currentPlayer = null;
            $this->requiredBoard = null;

            return;
        }

        // ничья
        if (!in_array(
            'playing',
            $this->boardStatuses,
            true
        )) {
            // все маленькие поля завершены, а общей победы нет
            $this->status = 'draw';
            $this->currentPlayer = null;
            $this->requiredBoard = null;

            return;
        }

        // игра продолжается
        $this->requiredBoard =
            $this->boardStatuses[$cell] === 'playing'
                ? $cell
                : null;

        $this->currentPlayer = $symbol === 'X'
            ? 'O'
            : 'X';
    }

    private function findWinningLine(
        array $values,
        string $symbol
    ): ?array {
        foreach(self::WINNING_LINES as $line) {
            [$first, $second, $third] = $line;
            if (
                $values[$first] === $symbol 
                && $values[$second] === $symbol
                && $values[$third] === $symbol
            ) {
                return $line;
            }
        }
        return null;
    }

    public function getState(): array
    {
        return [
            'mode' => 'ultimate',
            'boards' => $this->boards,
            'boardStatuses' => $this->boardStatuses,
            'requiredBoard' => $this->requiredBoard,
            'currentPlayer' => $this->currentPlayer,
            'status' => $this->status,
            'winner' => $this->winner,
            'winningLine' => $this->winningLine,
        ];
    }
}