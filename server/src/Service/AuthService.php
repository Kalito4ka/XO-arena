<?php

// класс регистрирует пользователей
// проверяет данные при входе и ищет пользователя по ID

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use PDO;
use PDOException;

final class AuthService
{
    public function __construct(private PDO $db){}

    // регистрация пользователя
    public function register(
        string $nickname,
        string $password
    ): array {
        $nickname = trim($nickname);

        $this->validateNickname($nickname);
        $this->validatePassword($password);

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $statement = $this->db->prepare(
            'INSERT INTO users (nickname, password_hash)
             VALUES (:nickname, :password_hash)
             RETURNING id, nickname, created_at'
        );

        try {
            $statement->execute([
                'nickname' => $nickname,
                'password_hash' => $passwordHash,
            ]);
        } catch (PDOException $error) {
            // нарушение уникальности
            if ($error->getCode() === '23505') {
                throw new ApiException(
                    409,
                    'Этот никнейм уже занят.'
                );
            }

            throw $error;
        }

        return $this->publicUser($statement->fetch());
    }

    // вход пользователя
    public function login(
        string $nickname,
        string $password
    ): array {
        $nickname = trim($nickname);

        $this->validateNickname($nickname);
        $this->validatePassword($password);

        $statement = $this->db->prepare(
            'SELECT id, nickname, password_hash, created_at
             FROM users
             WHERE LOWER(nickname) = LOWER(:nickname)'
        );

        $statement->execute([
            'nickname' => $nickname,
        ]);

        $row = $statement->fetch();

        if (
            $row === false
            || !password_verify($password, $row['password_hash'])
        ) {
            throw new ApiException(
                401,
                'Неверный никнейм или пароль.'
            );
        }

        return $this->publicUser($row);
    }

    // поиск пользователя по числовому идентификатору
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, nickname, created_at
             FROM users
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false
            ? null
            : $this->publicUser($row);
    }

    // внутренняя проверка никнейма
    private function validateNickname(string $nickname): void
    {
        if (
            preg_match(
                '/\A[A-Za-z0-9_]{3,24}\z/',
                $nickname
            ) !== 1
        ) {
            throw new ApiException(
                422,
                'Никнейм: 3–24 латинские буквы, цифры или знак _.'
            );
        }
    }

    // внутренняя проверка пароля
    private function validatePassword(string $password): void
    {
        $length = preg_match_all('/./us', $password);

        if (
            $length === false
            || $length < 10
            || strlen($password) > 72
            || str_contains($password, "\0")
        ) {
            throw new ApiException(
                422,
                'Пароль должен содержать не менее 10 символов '
                . 'и занимать не более 72 байт.'
            );
        }
    }

    // приводит строку из базы к формату ответа приложения
    private function publicUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nickname' => $row['nickname'],
            'createdAt' => $row['created_at'],
        ];
    }
}