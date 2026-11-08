<?php

// Обслуживает режимы

declare(strict_types=1);

namespace App\Service;

use App\Game\ClassicGame;
use App\Game\UltimateGame;
use App\Game\GobblersGame;
use App\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/GameRoomService.php';

final class ClassicGameService
{
    // понимает, от имени какого аккаунта выполняется запрос
    public function __construct(
        private PDO $db,
        private ?int $userId = null,
        private ?string $guestKey = null
    ) {
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

            $statement = $this->db->prepare(
                'INSERT INTO games (
                    id,
                    mode,
                    x_token_hash,
                    x_user_id,
                    x_guest_key
                )
                VALUES (
                    :id,
                    :mode,
                    :token_hash,
                    :user_id,
                    :guest_key
                )
                RETURNING *'
            );

            $statement->execute([
                'id' => $id,
                'mode' => $mode,
                'token_hash' => hash('sha256', $token),
                'user_id' => $this->userId,
                'guest_key' => $this->guestKey,
            ]);

            $row = $statement->fetch();

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
                    $column = $symbol === 'X'
                        ? 'x_user_id'
                        : 'o_user_id';

                    if (
                        $row[$column] !== null
                        && (int) $row[$column] === $this->userId
                    ) {
                        return [
                            'game' => $this->view($row),
                            'symbol' => $symbol,
                            'playerToken' => null,
                        ];
                    }
                }
            }

            if ($row['o_token_hash'] !== null) {
                throw new ApiException(
                    409,
                    'В партии уже два участника.'
                );
            }

            if ($this->guestKey !== null && $row['x_guest_key'] === $this->guestKey) {
                throw new ApiException(409, 'Вы уже создали эту партию.');
            }

            (new GameRoomService($this->db))->preparePlayer(
                $row['mode'], $this->userId, $this->guestKey, $id
            );

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $statement = $this->db->prepare(
                'UPDATE games
                SET o_token_hash = :token_hash,
                    o_user_id = :user_id,
                    o_guest_key = :guest_key,
                    updated_at = clock_timestamp()
                WHERE id = :id
                RETURNING *'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
                'user_id' => $this->userId,
                'guest_key' => $this->guestKey,
                'id' => $id,
            ]);

            $row = $statement->fetch();

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

            if ($row['rematch_id'] !== null) {
                return ['game' => $this->view($row), 'symbol' => $symbol];
            }

            if ($row['status'] === 'playing' || $this->rematchSeconds($row) <= 0) {
                throw new ApiException(409, 'Время для повторной игры истекло.');
            }

            $rooms = new GameRoomService($this->db);
            $rooms->checkAvailable($row['mode'], $this->userId, $this->guestKey);

            $column = $symbol === 'X' ? 'rematch_x' : 'rematch_o';
            $statement = $this->db->prepare(
                "UPDATE games SET $column = TRUE
                 WHERE id = :id
                   AND finished_at + INTERVAL '30 seconds' > clock_timestamp()
                 RETURNING *"
            );
            $statement->execute(['id' => $id]);
            $row = $statement->fetch();

            if ($row === false) {
                throw new ApiException(409, 'Время для повторной игры истекло.');
            }

            if ($row['rematch_x'] && $row['rematch_o']) {
                foreach (['x', 'o'] as $side) {
                    $rooms->preparePlayer(
                        $row['mode'],
                        $row[$side . '_user_id'] === null
                            ? null : (int) $row[$side . '_user_id'],
                        $row[$side . '_guest_key']
                    );
                }

                $nextId = bin2hex(random_bytes(16));
                $statement = $this->db->prepare(
                    'INSERT INTO games (
                        id, mode, x_user_id, o_user_id,
                        x_token_hash, o_token_hash, x_guest_key, o_guest_key,
                        updated_at
                     ) VALUES (
                        :next_id, :mode, :x_user, :o_user,
                        :x_token, :o_token, :x_guest, :o_guest,
                        clock_timestamp()
                     )'
                );
                $statement->execute([
                    'next_id' => $nextId,
                    'mode' => $row['mode'],
                    'x_user' => $row['x_user_id'],
                    'o_user' => $row['o_user_id'],
                    'x_token' => $row['x_token_hash'],
                    'o_token' => $row['o_token_hash'],
                    'x_guest' => $row['x_guest_key'],
                    'o_guest' => $row['o_guest_key'],
                ]);

                $statement = $this->db->prepare(
                    'UPDATE games SET rematch_id = :next_id WHERE id = :id RETURNING *'
                );
                $statement->execute(['next_id' => $nextId, 'id' => $id]);
                $row = $statement->fetch();
            }

            return ['game' => $this->view($row), 'symbol' => $symbol];
        });
    }

    // отсчитывает тридцать секунд от завершения партии по часам базы
    private function rematchSeconds(array $row): float
    {
        if ($row['finished_at'] === null) {
            return 0;
        }

        $statement = $this->db->prepare(
            "SELECT GREATEST(0, EXTRACT(EPOCH FROM (
                CAST(:finished_at AS timestamptz) + INTERVAL '30 seconds'
                - clock_timestamp()
            )))"
        );
        $statement->execute(['finished_at' => $row['finished_at']]);

        return (float) $statement->fetchColumn();
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

                if ($row['status'] !== 'playing') {
                    throw new ApiException(
                        409,
                        'Партия уже завершена.'
                    );
                }

                $row = (new GameTimeoutService($this->db))
                    ->expireLocked($row);

                if ($row['status'] !== 'playing') {
                    return [
                        'game' => $this->view($row),
                        'symbol' => $symbol,
                    ];
                }

                if ($row['o_token_hash'] === null) {
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
                    $row['mode'] !== 'gobblers'
                    && ($size !== null || $from !== null)
                ) {
                    throw new ApiException(
                        422,
                        'Размер и исходная клетка используются только в пожирающем режиме.'
                    );
                }

                if ($row['mode'] === 'ultimate') {
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
                } elseif ($row['mode'] === 'gobblers') {
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

                $json = json_encode(
                    $moves,
                    JSON_THROW_ON_ERROR
                );

                $statement = $this->db->prepare(
                    'UPDATE games
                     SET moves = CAST(:moves AS jsonb),
                         updated_at = clock_timestamp()
                     WHERE id = :id
                     RETURNING *'
                );

                $statement->execute([
                    'moves' => $json,
                    'id' => $id,
                ]);

                $row = $statement->fetch();

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
        $sql = "
            SELECT g.id,
                   g.mode,
                   g.status,
                   g.winner,
                   g.x_user_id,
                   g.created_at,
                   g.finished_at,
                   CASE
                       WHEN g.o_token_hash IS NULL THEN 'waiting'
                       ELSE 'playing'
                   END AS progress,
                   x.nickname AS x_nickname,
                   o.nickname AS o_nickname
            FROM games g
            LEFT JOIN users x ON x.id = g.x_user_id
            LEFT JOIN users o ON o.id = g.o_user_id
            WHERE (
                g.x_user_id = :x_user_id
                OR g.o_user_id = :o_user_id
            )
        ";

        if ($finished) {
            $sql .= "
                AND g.status IN ('won', 'draw')
                ORDER BY g.finished_at DESC, g.id DESC
                LIMIT 10
            ";
        } else {
            $sql .= "
                AND g.status = 'playing'
                AND (g.o_token_hash IS NOT NULL
                     OR g.created_at > clock_timestamp() - INTERVAL '3 minutes')
                ORDER BY g.created_at DESC, g.id DESC
            ";
        }

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'x_user_id' => $this->userId,
            'o_user_id' => $this->userId,
        ]);

        $items = [];

        foreach ($statement->fetchAll() as $row) {
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

    private function load(string $id, bool $lock = false): array
    {
        $sql = 'SELECT *
                FROM games
                WHERE id = :id
                  AND (o_token_hash IS NOT NULL
                       OR created_at > clock_timestamp() - INTERVAL \'3 minutes\')';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->db->prepare($sql);
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        if ($row === false) {
            throw new ApiException(404, 'Партия не найдена.');
        }

        return $row;
    }

    // определяет, за какую сторону — X или O — имеет право действовать отправитель запроса
    private function identifyPlayer(
        array $row,
        ?string $token
    ): string {
        // сначала проверяет принадлежность аккаунту
        if ($this->userId !== null) {
            foreach (['X', 'O'] as $symbol) {
                $ownerColumn = $symbol === 'X'
                    ? 'x_user_id'
                    : 'o_user_id';

                if (
                    $row[$ownerColumn] !== null
                    && (int) $row[$ownerColumn] === $this->userId
                ) {
                    return $symbol;
                }
            }
        }

        // если аккаунт не является участником,
        // проверяет гостевой ключ
        if ($token !== null) {
            $tokenHash = hash('sha256', $token);

            foreach (['X', 'O'] as $symbol) {
                $hashColumn = $symbol === 'X'
                    ? 'x_token_hash'
                    : 'o_token_hash';

                $ownerColumn = $symbol === 'X'
                    ? 'x_user_id'
                    : 'o_user_id';

                if (
                    $row[$hashColumn] !== null
                    && hash_equals($row[$hashColumn], $tokenHash)
                ) {
                    if ($row[$ownerColumn] !== null) {
                        throw new ApiException(
                            $this->userId === null ? 401 : 403,
                            'Эта сторона принадлежит аккаунту. '
                            . 'Войдите в соответствующий аккаунт.'
                        );
                    }

                    return $symbol;
                }
            }
        }

        throw new ApiException(
            $this->userId === null ? 401 : 403,
            'Нет доступа к партии. '
            . 'Войдите в аккаунт участника '
            . 'или используйте сохранённый гостевой ключ.'
        );
    }

    // восстанавливает партию нужного режима из сохранённых ходов
    private function restore(array $row): array
    {
        $mode = $row['mode'];

        $game = match ($mode) {
            'classic' => new ClassicGame(),
            'ultimate' => new UltimateGame(),
            'gobblers' => new GobblersGame(),

            default => throw new RuntimeException(
                'В базе указан неизвестный режим партии.'
            ),
        };

        $moves = json_decode(
            $row['moves'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

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

    // рассчитывает оставшееся время хода по часам базы данных
    private function remainingTurnSeconds(array $row): float
    {
        $statement = $this->db->prepare(
            "SELECT GREATEST(
                0,
                EXTRACT(
                    EPOCH FROM (
                        CAST(:updated_at AS timestamptz)
                        + CASE CAST(:mode AS text)
                            WHEN 'classic' THEN INTERVAL '15 seconds'

                            WHEN 'gobblers' THEN INTERVAL '30 seconds'
                            ELSE INTERVAL '45 seconds'
                          END
                        - clock_timestamp()
                    )
                )
            )"
        );

        $statement->execute([
            'updated_at' => $row['updated_at'],
            'mode' => $row['mode'],
        ]);

        return (float) $statement->fetchColumn();
    }

    private function view(array $row): array
    {
        [$game, $moves] = $this->restore($row);

        $state = $game->getState();

        if ($row['end_reason'] === 'timeout') {
            $state['status'] = 'won';
            $state['winner'] = $row['winner'];
            $state['currentPlayer'] = null;
            $state['winningLine'] = null;

            if ($row['mode'] === 'ultimate') {
                $state['requiredBoard'] = null;
            }
        }

        $roomStatus = $row['o_token_hash'] === null
            ? 'waiting'
            : 'active';

        if ($state['status'] !== 'playing') {
            $roomStatus = 'finished';
        }

        $remainingSeconds = $roomStatus === 'active'
            ? $this->remainingTurnSeconds($row)
            : null;

        return [
            'id' => $row['id'],
            'roomStatus' => $roomStatus,
            'version' => count($moves),
            'state' => $state,

            'userIds' => [
                'X' => $row['x_user_id'] === null
                    ? null
                    : (int) $row['x_user_id'],

                'O' => $row['o_user_id'] === null
                    ? null
                    : (int) $row['o_user_id'],
            ],

            'createdAt' => $row['created_at'],
            'finishedAt' => $row['finished_at'],
            'endReason' => $row['end_reason'],
            'remainingSeconds' => $remainingSeconds,
            'rematch' => [
                'X' => (bool) $row['rematch_x'],
                'O' => (bool) $row['rematch_o'],
                'gameId' => $row['rematch_id'],
                'remainingSeconds' => $this->rematchSeconds($row),
            ],
        ];
    }

    private function transaction(callable $action): array
    {
        $this->db->beginTransaction();

        try {
            $this->db->query('SELECT pg_advisory_xact_lock(5819)');
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
