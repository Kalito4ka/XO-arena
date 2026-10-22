<?php

declare(strict_types=1);

require_once __DIR__ . '/../Game/ClassicGame.php';

use App\Game\ClassicGame;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function play(array $cells): ClassicGame
{
    $game = new ClassicGame();

    foreach ($cells as $index => $cell) {
        $symbol = $index % 2 === 0 ? 'X' : 'O';

        $game->move($symbol, $cell);
    }

    return $game;
}

function expectRejected(
    ClassicGame $game,
    string $symbol,
    int $cell,
    string $expectedException
): void {
    $before = $game->getState();
    $caught = null;

    try {
        $game->move($symbol, $cell);
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    check(
        $caught instanceof $expectedException,
        'Ход должен завершиться ожидаемым исключением.'
    );

    check(
        $game->getState() === $before,
        'Отклонённый ход не должен менять состояние.'
    );
}

$tests = [
    'Начальное состояние' => function (): void {
        $state = (new ClassicGame())->getState();

        check(
            $state['board'] === array_fill(0, 9, null),
            'Начальное поле должно быть пустым.'
        );

        check($state['currentPlayer'] === 'X', 'Первым должен ходить X.');
        check($state['status'] === 'playing', 'Партия должна быть активна.');
        check($state['winner'] === null, 'Победителя ещё нет.');
        check($state['winningLine'] === null, 'Победной линии ещё нет.');
    },

    'Ход и смена очереди' => function (): void {
        $game = play([4]);
        $state = $game->getState();

        check($state['board'][4] === 'X', 'В центре должен быть X.');
        check($state['currentPlayer'] === 'O', 'Следующим должен ходить O.');
    },

    'Отклонение неправильных действий' => function (): void {
        $game = new ClassicGame();

        expectRejected($game, 'A', 0, InvalidArgumentException::class);
        expectRejected($game, 'X', -1, InvalidArgumentException::class);
        expectRejected($game, 'X', 9, InvalidArgumentException::class);
        expectRejected($game, 'O', 0, DomainException::class);

        $game->move('X', 0);

        expectRejected($game, 'X', 1, DomainException::class);
        expectRejected($game, 'O', 0, DomainException::class);
    },

    'Все восемь победных линий X' => function (): void {
        $cases = [
            [[0, 3, 1, 4, 2], [0, 1, 2]],
            [[3, 0, 4, 1, 5], [3, 4, 5]],
            [[6, 0, 7, 1, 8], [6, 7, 8]],

            [[0, 1, 3, 2, 6], [0, 3, 6]],
            [[1, 0, 4, 2, 7], [1, 4, 7]],
            [[2, 0, 5, 1, 8], [2, 5, 8]],

            [[0, 1, 4, 2, 8], [0, 4, 8]],
            [[2, 0, 4, 1, 6], [2, 4, 6]],
        ];

        foreach ($cases as [$moves, $expectedLine]) {
            $state = play($moves)->getState();

            check($state['status'] === 'won', 'Должна быть победа.');
            check($state['winner'] === 'X', 'Победителем должен быть X.');
            check(
                $state['winningLine'] === $expectedLine,
                'Неверная победная линия.'
            );
            check(
                $state['currentPlayer'] === null,
                'После победы следующего игрока нет.'
            );
        }
    },

    'Победа O' => function (): void {
        $state = play([0, 3, 1, 4, 8, 5])->getState();

        check($state['status'] === 'won', 'Должна быть победа.');
        check($state['winner'] === 'O', 'Победителем должен быть O.');
    },

    'Ничья' => function (): void {
        $state = play([0, 1, 2, 4, 3, 5, 7, 6, 8])->getState();

        check($state['status'] === 'draw', 'Должна быть ничья.');
        check($state['winner'] === null, 'При ничьей победителя нет.');
        check($state['currentPlayer'] === null, 'Партия завершена.');
    },

    'Победа последним ходом' => function (): void {
        $state = play([0, 1, 4, 2, 5, 3, 6, 7, 8])->getState();

        check($state['status'] === 'won', 'Это победа, а не ничья.');
        check($state['winner'] === 'X', 'Победителем должен быть X.');
    },

    'Запрет ходов после завершения' => function (): void {
        $wonGame = play([0, 3, 1, 4, 2]);

        expectRejected($wonGame, 'O', 8, DomainException::class);

        $drawGame = play([0, 1, 2, 4, 3, 5, 7, 6, 8]);

        expectRejected($drawGame, 'O', 0, DomainException::class);
    },
];

$failed = 0;

foreach ($tests as $name => $test) {
    try {
        $test();

        echo "[OK] {$name}\n";
    } catch (Throwable $exception) {
        $failed++;

        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
    }
}

echo "\nВсего групп проверок: " . count($tests);
echo ". Ошибок: {$failed}.\n";

exit($failed === 0 ? 0 : 1);