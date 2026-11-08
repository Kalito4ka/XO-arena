<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use PDO;

require_once __DIR__ . '/../Entity/User.php';

final class UserRepository
{
    public function __construct(private PDO $db) {}

    public function create(string $nickname, string $passwordHash): User
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (nickname, password_hash)
             VALUES (:nickname, :password_hash) RETURNING *'
        );
        $statement->execute(['nickname' => $nickname, 'password_hash' => $passwordHash]);

        return $this->hydrate($statement->fetch());
    }

    public function find(int $id): ?User
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByNickname(string $nickname): ?User
    {
        $statement = $this->db->prepare(
            'SELECT * FROM users WHERE LOWER(nickname) = LOWER(:nickname)'
        );
        $statement->execute(['nickname' => $nickname]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'], $row['nickname'], $row['password_hash'], $row['created_at']
        );
    }
}
