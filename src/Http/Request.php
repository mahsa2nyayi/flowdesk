<?php
declare(strict_types=1);

namespace FlowDesk\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ip = '0.0.0.0',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default; // ignore array-style parameters (?q[]=x)
    }
}
