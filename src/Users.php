<?php
declare(strict_types=1);

namespace FlowDesk;

use PDO;

final class Users
{
    public const MIN_PASSWORD = 10;
    public const MAX_PASSWORD = 72; // bcrypt limit; keeps behaviour identical on every PHP build

    public function __construct(private PDO $db)
    {
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function find(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $s->execute([$username]);
        return $s->fetch() ?: null;
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, username, display_name, role, active, created_at FROM users ORDER BY username')->fetchAll();
    }

    public function create(string $username, string $displayName, string $password, string $role): int
    {
        $s = $this->db->prepare(
            'INSERT INTO users (username, display_name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $s->execute([$username, $displayName, self::hash($password), $role, Database::now()]);
        return (int) $this->db->lastInsertId();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([(int) $active, $id]);
    }

    public function updateHash(int $id, string $hash): void
    {
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }
}
