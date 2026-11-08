<?php

// Обслуживает режимы

declare(strict_types=1);

namespace App\Service;

use App\Entity\Game;
use App\Repository\GameRepository;
use App\Game\ClassicGame;
use App\Game\UltimateGame;
use App\Game\GobblersGame;
use App\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/GameRoomService.php';
require_once __DIR__ . '/../Repository/GameRepository.php';

final class GameService
{
    private GameRepository $games;

    // понимает, от имени какого аккаунта выполняется запрос
    public function __construct(
        private PDO $db,
        private ?int $userId = null,
        private ?string $guestKey = null
    ) {
        $this->games = new GameRepository($db);
    }

    // создаёт партию выбранного режима и выдаёт доступ первому игроку
    public function create(string $mode = 'classic'): array
    {
        if (
            !in_array(
                $mode,
                ['classic', 'ultimate', 'gobblers'],
                true
            )
        ) {
            throw new ApiException(
                422,
                'Допустимые режимы: classic, ultimate или gobblers.'
            );
        }

        return $this->transaction(function () use ($mode): array {
            $rooms = new GameRoomService($this->db);
            $rooms->preparePlayer($mode, $this->userId, $this->guestKey, null, true);

            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));

            $row = $this->games->createWaiting(
                $id, $mode, hash('sha256', $token), $this->userId, $this->guestKey
            );

            return [
                'game' => $this->view($row),
                'symbol' => 'X',
                'playerToken' => $this->userId === null
                    ? $token
                    : null,
            ];
        });
    }

    public function join(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $row = $this->load($id, true);

            // игрок с аккаунтом может безопасно повторить
            // присоединение: сервер вернёт его прежнюю сторону
            if ($this->userId !== null) {
                foreach (['X', 'O'] as $symbol) {
                    if ($row->userIdFor($symbol) === $this->userId) {
                        return [
                            'game' => $this->view($row),
                            'symbol' => $symbol,
                            'playerToken' => null,
                        ];
                    }
                }
            }

            if (!$row->isWaiting()) {
                throw new ApiException(
                    409,
                    'В партии уже два участника.'
                );
            }

            if ($this->guestKey !== null && $row->guestKeyFor('X') === $this->guestKey) {
                throw new ApiException(409, 'Вы уже создали эту партию.');
            }

            (new GameRoomService($this->db))->preparePlayer(
                $row->mode, $this->userId, $this->guestKey, $id
            );

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $row = $this->games->join($id, $tokenHash, $this->userId, $this->guestKey);

            return [
                'game' => $this->view($row),
                'symbol' => 'O',
                'playerToken' => $this->userId === null
                    ? $token
                    : null,
            ];
        });
    }

    // принимает согласие участника и создаёт новую партию после двух согласий
    public function rematch(string $id, ?string $token): array
    {
        return $this->transaction(function () use ($id, $token): array {
            $row = $this->load($id, true);
            $symbol = $this->identifyPlayer($row, $token);

            if ($row->rematchId !== null) {
                return ['game' => $this->view($row), 'symbol' => $symbol];
            }
            if ($row->status === 'playing' || $this->games->remainingRematchSeconds($row) <= 0) {
                throw new ApiException(409, 'Время для повторной игры истекло.');
            }

            $rooms = new GameRoomService($this->db);
            $rooms->checkAvailable($row->mode, $this->userId, $this->guestKey);
            $row = $this->games->acceptRematch($id, $symbol);
            if ($row === null) {
                throw new ApiException(409, 'Время для повторной игры истекло.');
            }

            if ($row->rematchX && $row->rematchO) {
                foreach (['X', 'O'] as $side) {
                    $rooms->preparePlayer($row->mode, $row->userIdFor($side), $row->guestKeyFor($side));
                }
                $next = $this->games->createRematch(bin2hex(random_bytes(16)), $row);
                $row = $this->games->linkRematch($id, $next->id);
            }

            return ['game' => $this->view($row), 'symbol' => $symbol];
        });
    }

    public function get(string $id, ?string $token): array
    {
        $row = $this->load($id);
        $symbol = $this->identifyPlayer($row, $token);

        return [
            'game' => $this->view($row),
            'symbol' => $symbol,
        ];
    }

    public function move(
        string $id,
        ?string $token,
        int $cell,
        int $expectedVersion,
        ?int $board = null,
        ?int $size = null,
        ?int $from = null
    ): array {
        return $this->transaction(
            function () use (
                $id,
                $token,
                $cell,
                $expectedVersion,
                $board,
                $size,
                $from
            ): array {
                $row = $this->load($id, true);
                $symbol = $this->identifyPlayer($row, $token);

                if ($row->status !== 'playing') {
                    throw new ApiException(
                        409,
                        'Партия уже завершена.'
                    );
                }

                $row = (new GameTimeoutService($this->db))
                    ->expireLocked($row);

                if ($row->status !== 'playing') {
                    return [
                        'game' => $this->view($row),
                        'symbol' => $symbol,
                    ];
                }

                if ($row->isWaiting()) {
                    throw new ApiException(
                        409,
                        'Дождитесь второго игрока.'
                    );
                }

                [$game, $moves] = $this->restore($row);

                if (count($moves) !== $expectedVersion) {
                    throw new ApiException(
                        409,
                        'Состояние партии изменилось. Запросите его заново.'
                    );
                }

                if (
                    $row->mode !== 'gobblers'
                    && ($size !== null || $from !== null)
                ) {
                    throw new ApiException(
                        422,
                        'Размер и исходная клетка используются только в пожирающем режиме.'
                    );
                }

                if ($row->mode === 'ultimate') {
                    if ($board === null) {
                        throw new ApiException(
                            422,
                            'Для ультимативного хода нужен номер поля board.'
                        );
                    }

                    $game->move($symbol, $board, $cell);

                    $moves[] = [
                        'board' => $board,
                        'cell' => $cell,
                    ];
                } elseif ($row->mode === 'gobblers') {
                    if ($board !== null || $size === null) {
                        throw new ApiException(
                            422,
                            'Для пожирающего хода нужен size; параметр board не используется.'
                        );
                    }

                    $game->move($symbol, $cell, $size, $from);

                    $moves[] = [
                        'cell' => $cell,
                        'size' => $size,
                        'from' => $from,
                    ];
                } else {
                    if ($board !== null) {
                        throw new ApiException(
                            422,
                            'В классическом режиме параметр board не используется.'
                        );
                    }

                    $game->move($symbol, $cell);
                    $moves[] = $cell;
                }

                $row = $this->games->saveMoves($id, $moves);

                $row = (new GameResultService($this->db))->record(
                        $row,
                        $game->getState()
                    );

                return [
                    'game' => $this->view($row),
                    'symbol' => $symbol,
                ];
            }
        );
    }

    // получает активные партии, последние результаты и статистику аккаунта
    public function mine(): array
    {
        if ($this->userId === null) {
            throw new ApiException(
                401,
                'Для просмотра своих партий войдите в аккаунт.'
            );
        }

        return [
            'active' => $this->historyItems(false),
            'recent' => $this->historyItems(true),
            'stats' => (new GameResultService($this->db))->stats(
                $this->userId
            ),
        ];
    }

    // получает активные или последние завершённые партии аккаунта
    private function historyItems(bool $finished): array
    {
        $rows = $this->games->history($this->userId, $finished);

        $items = [];

        foreach ($rows as $row) {
            $symbol = (
                $row['x_user_id'] !== null
                && (int) $row['x_user_id'] === $this->userId
            ) ? 'X' : 'O';

            if ($row['status'] === 'playing') {
                $result = $row['progress'];
            } elseif ($row['status'] === 'draw') {
                $result = 'draw';
            } else {
                $result = $row['winner'] === $symbol
                    ? 'win'
                    : 'loss';
            }

            $opponent = $symbol === 'X'
                ? (
                    $row['progress'] === 'waiting'
                        ? 'Ожидается'
                        : ($row['o_nickname'] ?? 'Гость')
                )
                : ($row['x_nickname'] ?? 'Гость');

            $items[] = [
                'id' => $row['id'],
                'mode' => $row['mode'],
                'symbol' => $symbol,
                'opponent' => $opponent,
                'result' => $result,
                'createdAt' => $row['created_at'],
                'finishedAt' => $row['finished_at'],
            ];
        }

        return $items;
    }

    private function load(string $id, bool $lock = false): Game
    {
        return $this->games->find($id, $lock)
            ?? throw new ApiException(404, 'Партия не найдена.');
    }

    // Проверяет принадлежность стороны аккаунту или гостевому ключу.
    private function identifyPlayer(Game $row, ?string $token): string
    {
        if ($this->userId !== null) {
            foreach (['X', 'O'] as $symbol) {
                if ($row->userIdFor($symbol) === $this->userId) return $symbol;
            }
        }

        if ($token !== null) {
            $tokenHash = hash('sha256', $token);
            foreach (['X', 'O'] as $symbol) {
                $storedHash = $row->tokenHashFor($symbol);
                if ($storedHash !== null && hash_equals($storedHash, $tokenHash)) {
                    if ($row->userIdFor($symbol) !== null) {
                        throw new ApiException(
                            $this->userId === null ? 401 : 403,
                            'Эта сторона принадлежит аккаунту. Войдите в соответствующий аккаунт.'
                        );
                    }
                    return $symbol;
                }
            }
        }

        throw new ApiException(
            $this->userId === null ? 401 : 403,
            'Нет доступа к партии. Войдите в аккаунт участника или используйте сохранённый гостевой ключ.'
        );
    }

    // восстанавливает партию нужного режима из сохранённых ходов
    private function restore(Game $row): array
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

    private function view(Game $row): array
    {
        [$game, $moves] = $this->restore($row);

        $state = $game->getState();

        if ($row->endReason === 'timeout') {
            $state['status'] = 'won';
            $state['winner'] = $row->winner;
            $state['currentPlayer'] = null;
            $state['winningLine'] = null;

            if ($row->mode === 'ultimate') {
                $state['requiredBoard'] = null;
            }
        }

        $roomStatus = $row->isWaiting()
            ? 'waiting'
            : 'active';

        if ($state['status'] !== 'playing') {
            $roomStatus = 'finished';
        }

        $remainingSeconds = $roomStatus === 'active'
            ? $this->games->remainingTurnSeconds($row)
            : null;

        return [
            'id' => $row->id,
            'roomStatus' => $roomStatus,
            'version' => count($moves),
            'state' => $state,

            'userIds' => [
                'X' => $row->xUserId === null
                    ? null
                    : (int) $row->xUserId,

                'O' => $row->oUserId === null
                    ? null
                    : (int) $row->oUserId,
            ],

            'createdAt' => $row->createdAt,
            'finishedAt' => $row->finishedAt,
            'endReason' => $row->endReason,
            'remainingSeconds' => $remainingSeconds,
            'rematch' => [
                'X' => (bool) $row->rematchX,
                'O' => (bool) $row->rematchO,
                'gameId' => $row->rematchId,
                'remainingSeconds' => $this->games->remainingRematchSeconds($row),
            ],
        ];
    }

    private function transaction(callable $action): array
    {
        $this->db->beginTransaction();

        try {
            $this->games->lockPlayers();
            $result = $action();

            $this->db->commit();

            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }
}
