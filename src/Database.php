<?php
declare(strict_types=1);

namespace FlowDesk;

use PDO;

final class Database
{
    public static function connect(string $dsn, ?string $user = null, ?string $pass = null): PDO
    {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return $pdo;
    }

    public static function install(PDO $pdo): void
    {
        $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
