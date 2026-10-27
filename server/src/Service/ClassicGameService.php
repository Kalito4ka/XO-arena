<?php

declare(strict_types=1);

namespace App\Service;

use App\Game\ClassicGame;
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

    public function create(): array
    {
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));

        $statement = $this->db->prepare(
            'INSERT INTO games (id, x_token_hash, x_user_id)
            VALUES (:id, :token_hash, :user_id)
            RETURNING *'
        );

        $statement->execute([
            'id' => $id,
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
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
                'user_id' => $this->userId,
                'id' => $id,
            ]);

            $row['o_token_hash'] = $tokenHash;
            $row['o_user_id'] = $this->userId;

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
        int $expectedVersion
    ): array {
        return $this->transaction(
            function () use (
                $id,
                $token,
                $cell,
                $expectedVersion
            ): array {
                $row = $this->load($id, true);
                $symbol = $this->identifyPlayer($row, $token);

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

                $game->move($symbol, $cell);
                $moves[] = $cell;

                $json = json_encode(
                    $moves,
                    JSON_THROW_ON_ERROR
                );

                $statement = $this->db->prepare(
                    'UPDATE games
                     SET moves = CAST(:moves AS jsonb),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $statement->execute([
                    'moves' => $json,
                    'id' => $id,
                ]);

                $row['moves'] = $json;

                return [
                    'game' => $this->view($row),
                    'symbol' => $symbol,
                ];
            }
        );
    }

    // метод получает список партий текущего пользователя: за кого он играл, кто был соперником и чем закончилась игра
    public function mine(int $page): array
    {
        if ($this->userId === null) {
            throw new ApiException(
                401,
                'Для просмотра своих партий войдите в аккаунт.'
            );
        }

        $pageSize = 20;
        $offset = ($page - 1) * $pageSize;

        $statement = $this->db->prepare(
            'SELECT g.*,
                    x.nickname AS x_nickname,
                    o.nickname AS o_nickname
            FROM games g
            LEFT JOIN users x ON x.id = g.x_user_id
            LEFT JOIN users o ON o.id = g.o_user_id
            WHERE g.x_user_id = :x_user_id
                OR g.o_user_id = :o_user_id
            ORDER BY g.created_at DESC, g.id DESC
            LIMIT 21 OFFSET :offset'
        );

        $statement->bindValue(
            ':x_user_id',
            $this->userId,
            PDO::PARAM_INT
        );

        $statement->bindValue(
            ':o_user_id',
            $this->userId,
            PDO::PARAM_INT
        );

        $statement->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $statement->execute();

        $rows = $statement->fetchAll();

        $hasMore = count($rows) > $pageSize;
        $rows = array_slice($rows, 0, $pageSize);

        $items = [];

        foreach ($rows as $row) {
            $symbol = (
                $row['x_user_id'] !== null
                && (int) $row['x_user_id'] === $this->userId
            ) ? 'X' : 'O';

            $game = $this->view($row);
            $state = $game['state'];

            if ($game['roomStatus'] === 'waiting') {
                $result = 'waiting';
            } elseif ($state['status'] === 'playing') {
                $result = 'playing';
            } elseif ($state['status'] === 'draw') {
                $result = 'draw';
            } else {
                $result = $state['winner'] === $symbol
                    ? 'win'
                    : 'loss';
            }

            $opponent = $symbol === 'X'
                ? (
                    $row['o_token_hash'] === null
                        ? 'Ожидается'
                        : ($row['o_nickname'] ?? 'Гость')
                )
                : ($row['x_nickname'] ?? 'Гость');

            $items[] = [
                'id' => $row['id'],
                'mode' => 'classic',
                'symbol' => $symbol,
                'opponent' => $opponent,
                'result' => $result,
                'createdAt' => $row['created_at'],
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'hasMore' => $hasMore,
        ];
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

    private function restore(array $row): array
    {
        $moves = json_decode(
            $row['moves'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (
            !is_array($moves)
            || !array_is_list($moves)
            || count($moves) > 9
        ) {
            throw new RuntimeException(
                'В базе хранится некорректная история партии.'
            );
        }

        $game = new ClassicGame();

        foreach ($moves as $index => $cell) {
            if (!is_int($cell)) {
                throw new RuntimeException(
                    'В истории партии найден некорректный номер клетки.'
                );
            }

            $symbol = $index % 2 === 0 ? 'X' : 'O';

            try {
                $game->move($symbol, $cell);
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

    private function view(array $row): array
    {
        [$game, $moves] = $this->restore($row);

        $state = $game->getState();

        $roomStatus = $row['o_token_hash'] === null
            ? 'waiting'
            : 'active';

        if ($state['status'] !== 'playing') {
            $roomStatus = 'finished';
        }

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