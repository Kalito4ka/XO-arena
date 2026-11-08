<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiException;
use App\Repository\UserRepository;
use PDO;
use PDOException;

require_once __DIR__ . '/../Repository/UserRepository.php';

final class AuthService
{
    private UserRepository $users;

    public function __construct(PDO $db)
    {
        $this->users = new UserRepository($db);
    }

    public function register(string $nickname, string $password): array
    {
        $nickname = trim($nickname);
        $this->validateNickname($nickname);
        $this->validatePassword($password);

        try {
            $user = $this->users->create($nickname, password_hash($password, PASSWORD_DEFAULT));
        } catch (PDOException $error) {
            if ($error->getCode() === '23505') {
                throw new ApiException(409, 'Это имя пользователя уже занято.');
            }
            throw $error;
        }

        return $user->toPublicArray();
    }

    public function login(string $nickname, string $password): array
    {
        $nickname = trim($nickname);
        $this->validateNickname($nickname);
        $this->validatePassword($password);
        $user = $this->users->findByNickname($nickname);

        if ($user === null || !$user->verifiesPassword($password)) {
            throw new ApiException(401, 'Неверный никнейм или пароль.');
        }

        return $user->toPublicArray();
    }

    public function find(int $id): ?array
    {
        return $this->users->find($id)?->toPublicArray();
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

}
