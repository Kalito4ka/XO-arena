<?php

declare(strict_types=1);

namespace App\Entity;

// Данные пользователя. Пароль хранится только в виде хеша.
final readonly class User
{
    public function __construct(
        public int $id,
        public string $nickname,
        private string $passwordHash,
        public string $createdAt,
    ) {}

    public function verifiesPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'createdAt' => $this->createdAt,
        ];
    }
}
