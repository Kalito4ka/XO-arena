<?php

declare(strict_types=1);

require_once __DIR__ . '/../Game/UltimateGame.php';

use App\Game\UltimateGame;

// останавливает проверку при несовпадении результата
function checkUltimate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// проверяет отклонение хода без изменения партии
function rejectsUltimateMove(
    UltimateGame $game,
    callable $action
): void {
    $before = $game->getState();
    $rejected = false;

    try {
        $action();
    } catch (DomainException | InvalidArgumentException) {
        $rejected = true;
    }

    checkUltimate(
        $rejected,
        'Некорректный ход не был отклонён.'
    );

    checkUltimate(
        $before === $game->getState(),
        'Отклонённый ход изменил состояние партии.'
    );
}

// подготавливает состояние партии для проверки завершающего хода
function prepareUltimatePosition(
    array $boardStatuses,
    int $board,
    array $cells,
    string $currentPlayer
): UltimateGame {
    $game = new UltimateGame();

    $boards = [];

    foreach ($boardStatuses as $result) {
        $boards[] = match ($result) {
            'X' => [
                'X', 'X', 'X',
                'O', 'O', null,
                null, null, null,
            ],

            'O' => [
                'O', 'O', 'O',
                'X', 'X', null,
                null, null, null,
            ],

            'draw' => [
                'X', 'O', 'X',
                'X', 'O', 'O',
                'O', 'X', 'X',
            ],

            default => array_fill(0, 9, null),
        };
    }

    $boards[$board] = $cells;

    $values = [
        'boards' => $boards,
        'boardStatuses' => $boardStatuses,
        'requiredBoard' => $board,
        'currentPlayer' => $currentPlayer,
    ];

    foreach ($values as $name => $value) {
        $property = new ReflectionProperty(
            UltimateGame::class,
            $name
        );

        $property->setValue($game, $value);
    }

    return $game;
}

try {
    $game = new UltimateGame();
    $state = $game->getState();

    checkUltimate(
        count($state['boards']) === 9
        && count($state['boards'][0]) === 9
        && $state['requiredBoard'] === null
        && $state['currentPlayer'] === 'X',
        'Некорректное начальное состояние.'
    );

    $game->move('X', 4, 2);
    $state = $game->getState();

    checkUltimate(
        $state['boards'][4][2] === 'X'
        && $state['requiredBoard'] === 2
        && $state['currentPlayer'] === 'O',
        'Первый ход неверно определил следующее поле.'
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('O', 3, 0)
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('X', 2, 0)
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('O', 2, 9)
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('Z', 2, 0)
    );

    $game->move('O', 2, 4);
    $state = $game->getState();

    checkUltimate(
        $state['boards'][2][4] === 'O'
        && $state['requiredBoard'] === 4
        && $state['currentPlayer'] === 'X',
        'Второй ход неверно определил следующее поле.'
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('X', 4, 2)
    );

    fwrite(
        STDOUT,
        "Начальные проверки ультимативного режима пройдены\n"
    );

    $game = new UltimateGame();

    $game->move('X', 0, 1);
    $game->move('O', 1, 0);

    $game->move('X', 0, 2);
    $game->move('O', 2, 0);

    $game->move('X', 0, 0);

    $state = $game->getState();

    checkUltimate(
        $state['boardStatuses'][0] === 'X',
        'Победа на маленьком поле не определена.'
    );

    checkUltimate(
        $state['status'] === 'playing'
        && $state['winner'] === null,
        'Одна маленькая победа завершила всю партию.'
    );

    checkUltimate(
        $state['requiredBoard'] === null,
        'Направление на выигранное поле не дало свободный выбор.'
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('O', 0, 3)
    );

    $game->move('O', 8, 4);

    checkUltimate(
        $game->getState()['boards'][8][4] === 'O',
        'Свободный выбор открытого поля не работает.'
    );

        $lines = [
        [0, 1, 2],
        [3, 4, 5],
        [6, 7, 8],

        [0, 3, 6],
        [1, 4, 7],
        [2, 5, 8],

        [0, 4, 8],
        [2, 4, 6],
    ];

    foreach (['X', 'O'] as $symbol) {
        $opponent = $symbol === 'X' ? 'O' : 'X';

        foreach ($lines as $line) {
            $statuses = array_fill(0, 9, 'playing');

            $statuses[$line[0]] = $symbol;
            $statuses[$line[1]] = $symbol;

            $board = $line[2];

            $game = prepareUltimatePosition(
                $statuses,
                $board,
                [
                    $symbol, $symbol, null,
                    $opponent, $opponent, null,
                    null, null, null,
                ],
                $symbol
            );

            $game->move($symbol, $board, 2);

            $state = $game->getState();

            checkUltimate(
                $state['boardStatuses'][$board] === $symbol
                && $state['status'] === 'won'
                && $state['winner'] === $symbol
                && $state['winningLine'] === $line
                && $state['currentPlayer'] === null
                && $state['requiredBoard'] === null,
                'Неверно определена общая победа '
                . $symbol
                . ' на линии '
                . implode(', ', $line)
            );

            rejectsUltimateMove(
                $game,
                fn () => $game->move($opponent, 0, 8)
            );
        }
    }

        $game = prepareUltimatePosition(
        array_fill(0, 9, 'playing'),
        4,
        [
            'X', 'O', 'X',
            'X', 'O', 'O',
            'O', 'X', null,
        ],
        'X'
    );

    $game->move('X', 4, 8);

    $state = $game->getState();

    checkUltimate(
        $state['boardStatuses'][4] === 'draw'
        && $state['status'] === 'playing'
        && $state['requiredBoard'] === 8,
        'Неверно обработана ничья на маленьком поле.'
    );

        $statuses = array_fill(0, 9, 'playing');
    $statuses[4] = 'draw';

    $game = prepareUltimatePosition(
        $statuses,
        0,
        array_fill(0, 9, null),
        'X'
    );

    $game->move('X', 0, 4);

    checkUltimate(
        $game->getState()['requiredBoard'] === null,
        'Направление на ничейное поле не дало свободный выбор.'
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('O', 4, 0)
    );

    $game->move('O', 7, 3);

    checkUltimate(
        $game->getState()['boards'][7][3] === 'O',
        'После направления на ничейное поле нельзя выбрать открытое.'
    );

        $game = prepareUltimatePosition(
        [
            'X', 'O', 'X',
            'X', 'O', 'O',
            'O', 'X', 'playing',
        ],
        8,
        [
            'X', 'O', 'X',
            'X', 'O', 'O',
            'O', 'X', null,
        ],
        'X'
    );

    $game->move('X', 8, 8);

    $state = $game->getState();

    checkUltimate(
        $state['boardStatuses'][8] === 'draw'
        && $state['status'] === 'draw'
        && $state['winner'] === null
        && $state['winningLine'] === null
        && $state['currentPlayer'] === null
        && $state['requiredBoard'] === null,
        'Неверно определена общая ничья.'
    );

    rejectsUltimateMove(
        $game,
        fn () => $game->move('O', 8, 8)
    );

    fwrite(
        STDOUT,
        "Проверки правил ультимативного режима пройдены\n"
    );
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}