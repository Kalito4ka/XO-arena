<?php

// Обслуживает режимы

declare(strict_types=1);

namespace App\Service;

use App\Game\ClassicGame;
use App\Game\UltimateGame;
use App\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

final class ClassicGameService
{
    // понимает, от имени какого аккаунта выполняется запрос
    public function __construct(
        private PDO $db,
        private ?int $userId = null
    ) {
    }

    // создаёт партию выбранного режима и выдаёт доступ первому игроку
    public function create(string $mode = 'classic'): array
    {
        if (!in_array($mode, ['classic', 'ultimate'], true)) {
            throw new ApiException(
                422,
                'Допустимые режимы: classic или ultimate.'
            );
        }

        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));

        $statement = $this->db->prepare(
            'INSERT INTO games (
                id,
                mode,
                x_token_hash,
                x_user_id
            )
            VALUES (
                :id,
                :mode,
                :token_hash,
                :user_id
            )
            RETURNING *'
        );

        $statement->execute([
            'id' => $id,
            'mode' => $mode,
            'token_hash' => hash('sha256', $token),
            'user_id' => $this->userId,
        ]);

        $row = $statement->fetch();

        return [
            'game' => $this->view($row),
            'symbol' => 'X',
            'playerToken' => $this->userId === null
                ? $token
                : null,
        ];
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

            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

                        $statement = $this->db->prepare(
                'UPDATE games
                SET o_token_hash = :token_hash,
                    o_user_id = :user_id,
                    updated_at = clock_timestamp()
                WHERE id = :id
                RETURNING *'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
                'user_id' => $this->userId,
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
        ?int $board = null
    ): array {
        return $this->transaction(
            function () use (
                $id,
                $token,
                $cell,
                $expectedVersion,
                $board
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
                WHERE id = :id';

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

        $maximumMoves = $mode === 'classic' ? 9 : 81;

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
                            ELSE INTERVAL '30 seconds'
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
        ];
    }

    private function transaction(callable $action): array
    {
        $this->db->beginTransaction();

        try {
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