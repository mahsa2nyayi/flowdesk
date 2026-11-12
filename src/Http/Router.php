<?php
declare(strict_types=1);

namespace FlowDesk\Http;

final class Router
{
    /** @var list<array{string, string, callable, array}> */
    private array $routes = [];

    /** @param array{auth?: bool, roles?: list<string>} $opts */
    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts];
    }

    /** @return array{0: callable, 1: array, 2: array}|int  match, or 404 / 405 */
    public function match(string $method, string $path): array|int
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $regex, $handler, $opts]) {
            if (preg_match($regex, $path, $mm)) {
                $pathMatched = true;
                if ($m === $method) {
                    $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$handler, array_map('intval', $params), $opts];
                }
            }
        }
        return $pathMatched ? 405 : 404;
    }
}
