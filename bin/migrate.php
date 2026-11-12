<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use FlowDesk\Database;

$dsn = getenv('DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/var/flowdesk.sqlite';
Database::install(Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASS') ?: null));
echo "Schema installed on $dsn\n";
