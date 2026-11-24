<?php

declare(strict_types=1);

namespace App\Game;

use App\Entity\Game;
use RuntimeException;

require_once __DIR__ . '/ClassicGame.php';
require_once __DIR__ . '/UltimateGame.php';
require_once __DIR__ . '/GobblersGame.php';

final class GameHistory
{
    // восстанавливает партию нужного режима из сохранённых ходов
    public static function restore(Game $row): array
    {
        $mode = $row->mode;

        $game = match ($mode) {
            'classic' => new ClassicGame(),
            'ultimate' => new UltimateGame(),
            'gobblers' => new GobblersGame(),

            default => throw new RuntimeException(
                'В базе указан неизвестный режим партии.'
            ),
        };

        $moves = $row->moves;

        $maximumMoves = match ($mode) {
            'classic' => 9,
            'ultimate' => 81,
            'gobblers' => 100,
        };

        if (
            !is_array($moves)
            || !array_is_list($moves)
            || count($moves) > $maximumMoves
        ) {
            throw new RuntimeException(
                'В базе хранится некорректная история партии.'
            );
        }

        foreach ($moves as $index => $move) {
            $symbol = $index % 2 === 0 ? 'X' : 'O';

            try {
                if ($mode === 'ultimate') {
                    if (
                        !is_array($move)
                        || count($move) !== 2
                        || !isset($move['board'], $move['cell'])
                        || !is_int($move['board'])
                        || !is_int($move['cell'])
                    ) {
                        throw new RuntimeException(
                            'Некорректный формат ультимативного хода.'
                        );
                    }

                    $game->move(
                        $symbol,
                        $move['board'],
                        $move['cell']
                    );
                } elseif ($mode === 'gobblers') {
                    if (
                        !is_array($move)
                        || count($move) !== 3
                        || !isset($move['cell'], $move['size'])
                        || !is_int($move['cell'])
                        || !is_int($move['size'])
                        || !array_key_exists('from', $move)
                        || (
                            $move['from'] !== null
                            && !is_int($move['from'])
                        )
                    ) {
                        throw new RuntimeException(
                            'Некорректный формат пожирающего хода.'
                        );
                    }

                    $game->move(
                        $symbol,
                        $move['cell'],
                        $move['size'],
                        $move['from']
                    );
                } else {
                    if (!is_int($move)) {
                        throw new RuntimeException(
                            'Некорректный формат классического хода.'
                        );
                    }

                    $game->move($symbol, $move);
                }
            } catch (\DomainException | \InvalidArgumentException $error) {
                throw new RuntimeException(
                    'История партии нарушает правила игры.',
                    0,
                    $error
                );
            }
        }

        return [$game, $moves];
    }
}
