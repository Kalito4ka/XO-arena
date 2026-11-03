<?php

declare(strict_types=1);

namespace App\Game;

use DomainException;
use InvalidArgumentException;

final class GobblersGame
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

    private array $stacks;

    // кол-во фишек
    private array $reserves = [
        'X' => [1 => 2, 2 => 2, 3 => 2],
        'O' => [1 => 2, 2 => 2, 3 => 2],
    ];

    private ?string $currentPlayer = 'X';
    private string $status = 'playing';
    private ?string $winner = null;
    private ?array $winningLine = null;
    private int $moveCount = 0; // кол-во выполненных ходов

    public function __construct()
    {
        $this->stacks = array_fill(0, 9, []);
    }

    //выставляет фишку из запаса или перемещает верхнюю фишку
    public function move(
        string $symbol,
        int $cell, // куда поставить фишку
        int $size,
        ?int $from = null // откуда поставить фишку
    ): void {
        if (!in_array($symbol, ['X', 'O'], true)) {
            throw new InvalidArgumentException(
                'Допустимы только символы X и O.'
            );
        }

        if (
            $cell < 0 || $cell > 8
            || ($from !== null && ($from < 0 || $from > 8))
        ) {
            throw new InvalidArgumentException(
                'Номер клетки должен быть от 0 до 8.'
            );
        }

        if ($size < 1 || $size > 3) {
            throw new InvalidArgumentException(
                'Размер фишки должен быть от 1 до 3.'
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

        if ($from === $cell) {
            throw new DomainException(
                'Нужно выбрать другую клетку.'
            );
        }

        if ($from === null) {
            // берем фишку из запаса
            if ($this->reserves[$symbol][$size] === 0) {
                throw new DomainException(
                    'В запасе нет фишек такого размера.'
                );
            }
        } else {
            // перемещаем фишку
            $source = $this->top($from); // получаем верхнюю фишку

            if (
                $source === null
                || $source['symbol'] !== $symbol
                || $source['size'] !== $size
            ) {
                throw new DomainException(
                    'Можно перемещать только свою верхнюю фишку.'
                );
            }
        }

        $target = $this->top($cell); // получаем верхнюю фишку клетки назначения

        if ($target !== null && $target['size'] >= $size) {
            throw new DomainException(
                'Накрыть можно только фишку меньшего размера.'
            );
        }

        $opponent = $symbol === 'X' ? 'O' : 'X';

        // все проверки завершены, меняем состояние игры
        if ($from === null) {
            // берем фишку из запаса
            $this->reserves[$symbol][$size]--;
        } else {
            // перемещаем фишку по полю
            array_pop($this->stacks[$from]);

            // проверяем, не образовалась ли у соперника выйгрышная линия
            $line = $this->findWinningLine($opponent);

            if ($line !== null) {
                $this->moveCount++;

                $this->finish($opponent, $line);

                return;
            }
        }

        // добавляем фишку в конец стопки клетки назначения
        $this->stacks[$cell][] = [
            'symbol' => $symbol,
            'size' => $size,
        ];

        $this->moveCount++;

        // проверяем выйгрыш
        $line = $this->findWinningLine($symbol);

        if ($line !== null) {
            $this->finish($symbol, $line);

            return;
        }

        // ограничиваем партию 100 ходами
        if ($this->moveCount >= 100) {
            $this->status = 'draw';

            $this->currentPlayer = null;

            return;
        }

        $this->currentPlayer = $opponent;
    }

    // возвращает верхнюю фишку или null
    private function top(int $cell): ?array
    {
        $stack = $this->stacks[$cell];

        return $stack === []
            ? null
            : $stack[count($stack) - 1];
    }

    // проверяет выйгрышную линию
    private function findWinningLine(string $symbol): ?array
    {
        foreach (self::WINNING_LINES as $line) {

            [$first, $second, $third] = $line;

            if (
                ($this->top($first)['symbol'] ?? null) === $symbol
                && ($this->top($second)['symbol'] ?? null) === $symbol
                && ($this->top($third)['symbol'] ?? null) === $symbol
            ) {
                return $line;
            }
        }
        return null;
    }

    // завершает партию победой
    private function finish(string $winner, array $line): void
    {
        $this->status = 'won';
        $this->winner = $winner;
        $this->winningLine = $line;

        $this->currentPlayer = null;
    }

    // собирает допустимые выставления и перемещения текущего игрока
    public function getLegalMoves(): array
    {
        if ($this->status !== 'playing') {
            return [];
        }

        $symbol = $this->currentPlayer;
        $pieces = [];

        foreach ($this->reserves[$symbol] as $size => $count) {
            if ($count > 0) {
                $pieces[] = [
                    'size' => $size,
                    'from' => null,
                ];
            }
        }

        for ($cell = 0; $cell < 9; $cell++) {
            $piece = $this->top($cell);

            if ($piece !== null && $piece['symbol'] === $symbol) {
                $pieces[] = [
                    'size' => $piece['size'],
                    'from' => $cell,
                ];
            }
        }

        $moves = [];

        foreach ($pieces as $piece) {
            for ($cell = 0; $cell < 9; $cell++) {
                if ($piece['from'] === $cell) {
                    continue;
                }

                $target = $this->top($cell);

                if (
                    $target === null
                    || $target['size'] < $piece['size']
                ) {
                    $moves[] = [
                        'cell' => $cell,
                        'size' => $piece['size'],
                        'from' => $piece['from'],
                    ];
                }
            }
        }

        return $moves;
    }

    // собирает текущее состояние
    // возвращает поле, запас фишек и результат партии
    public function getState(): array
    {
        $board = [];

        for ($cell = 0; $cell < 9; $cell++) {
            $board[] = $this->top($cell);
        }

        return [
            'mode' => 'gobblers',
            'board' => $board,
            'stacks' => $this->stacks,
            'reserves' => $this->reserves,
            'currentPlayer' => $this->currentPlayer,
            'status' => $this->status,
            'winner' => $this->winner,
            'winningLine' => $this->winningLine,
            'moveCount' => $this->moveCount,
        ];
    }
}