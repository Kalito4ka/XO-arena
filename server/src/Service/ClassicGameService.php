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
    public function __construct(private PDO $db)
    {
    }

    public function create(): array
    {
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));

        $statement = $this->db->prepare(
            'INSERT INTO games (id, x_token_hash)
             VALUES (:id, :token_hash)
             RETURNING id, moves, o_token_hash'
        );

        $statement->execute([
            'id' => $id,
            'token_hash' => hash('sha256', $token),
        ]);

        $row = $statement->fetch();

        return [
            'game' => $this->view($row),
            'symbol' => 'X',
            'playerToken' => $token,
        ];
    }

    public function join(string $id): array
    {
        return $this->transaction(function () use ($id): array {
            $row = $this->load($id, true);

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
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $statement->execute([
                'token_hash' => $tokenHash,
                'id' => $id,
            ]);

            $row['o_token_hash'] = $tokenHash;

            return [
                'game' => $this->view($row),
                'symbol' => 'O',
                'playerToken' => $token,
            ];
        });
    }

    public function get(string $id, string $token): array
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
        string $token,
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

    private function load(string $id, bool $lock = false): array
    {
        $sql = 'SELECT id, moves, x_token_hash, o_token_hash
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

    private function identifyPlayer(array $row, string $token): string
    {
        $tokenHash = hash('sha256', $token);

        if (hash_equals($row['x_token_hash'], $tokenHash)) {
            return 'X';
        }

        if (
            $row['o_token_hash'] !== null
            && hash_equals($row['o_token_hash'], $tokenHash)
        ) {
            return 'O';
        }

        throw new ApiException(
            403,
            'Ключ не принадлежит участнику этой партии.'
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