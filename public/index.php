<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use FlowDesk\{App, Database};
use FlowDesk\Http\Request;

$root  = dirname(__DIR__);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$debug = getenv('APP_DEBUG') === '1';

ini_set('display_errors', $debug ? '1' : '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('flowdesk');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$dsn = getenv('DB_DSN') ?: 'sqlite:' . $root . '/var/flowdesk.sqlite';
$fresh = str_starts_with($dsn, 'sqlite:') && !is_file(substr($dsn, 7));
$pdo   = Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASS') ?: null);
if ($fresh) {
    Database::install($pdo);   // first run with the default SQLite file: create the schema
}

$app = new App($pdo, $_SESSION, ['debug' => $debug, 'https' => $https], static fn() => session_regenerate_id(true));
$app->handle(Request::fromGlobals())->send();
