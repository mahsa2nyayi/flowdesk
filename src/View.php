<?php
declare(strict_types=1);

namespace FlowDesk;

final class View
{
    public function __construct(private string $dir)
    {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], array $shared = []): string
    {
        $content = $this->capture($template, $vars + $shared);
        return $this->capture('layout', $shared + ['content' => $content, 'title' => $vars['title'] ?? 'FlowDesk']);
    }

    private function capture(string $template, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $this->dir . '/' . $template . '.php';
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
