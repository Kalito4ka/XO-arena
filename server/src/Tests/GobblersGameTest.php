<?php

declare(strict_types=1);

require_once __DIR__ . '/../Game/GobblersGame.php';

use App\Game\GobblersGame;

// останавливает проверку при неправильном результате
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// проверяет запрет хода и сохранность состояния
function expectRejected(
    GobblersGame $game,
    string $symbol,
    int $cell,
    int $size,
    ?int $from = null
): void {
    $before = $game->getState();
    $rejected = false;

    try {
        $game->move($symbol, $cell, $size, $from);
    } catch (DomainException) {
        $rejected = true;
    }

    check($rejected, 'Запрещённый ход был принят.');
    check(
        $game->getState() === $before,
        'Запрещённый ход изменил состояние.'
    );
}

$game = new GobblersGame();

check(
    $game->getState()['reserves']['X'][1] === 2,
    'В запасе должны быть две маленькие фишки.'
);

$game->move('X', 0, 1);
$game->move('O', 0, 2);

check(
    $game->getState()['board'][0]['symbol'] === 'O',
    'Средняя фишка должна накрыть маленькую.'
);

expectRejected($game, 'X', 0, 2);

$game->move('X', 8, 1);
$game->move('O', 4, 2, 0);

check(
    $game->getState()['board'][0]['symbol'] === 'X',
    'После перемещения должна открыться нижняя фишка.'
);

check(
    $game->getState()['reserves']['O'][2] === 1,
    'Перемещение не должно расходовать фишку из запаса.'
);

expectRejected($game, 'X', 7, 1);

$game = new GobblersGame();

$game->move('X', 0, 1);
$game->move('O', 3, 1);
$game->move('X', 1, 2);
$game->move('O', 4, 1);
$game->move('X', 2, 3);

check(
    $game->getState()['winner'] === 'X',
    'Три видимые фишки в ряд должны дать победу.'
);

expectRejected($game, 'O', 8, 3);

$game = new GobblersGame();

$game->move('X', 8, 1);
$game->move('O', 0, 1);
$game->move('X', 0, 3);
$game->move('O', 1, 1);
$game->move('X', 7, 2);
$game->move('O', 2, 2);

$game->move('X', 4, 3, 0);

check(
    $game->getState()['winner'] === 'O',
    'Открытие линии соперника должно дать ему победу.'
);

$game = new GobblersGame();

$before = $game->getState();
$legalMoves = $game->getLegalMoves();

check(
    count($legalMoves) === 27,
    'В начале должны быть доступны 3 размера на 9 клетках.'
);

foreach ($legalMoves as $move) {
    $copy = clone $game;

    $copy->move(
        'X',
        $move['cell'],
        $move['size'],
        $move['from']
    );
}

check(
    $game->getState() === $before,
    'Получение допустимых ходов не должно менять партию.'
);

echo "Все проверки пожирающего режима пройдены.\n";