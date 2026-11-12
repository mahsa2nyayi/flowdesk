<?php
declare(strict_types=1);

// Dependency-free PSR-4 autoloader so the project runs without `composer install`.
spl_autoload_register(static function (string $class): void {
    $prefix = 'FlowDesk\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require_once __DIR__ . '/helpers.php';
