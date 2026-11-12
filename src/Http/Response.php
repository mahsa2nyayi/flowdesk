<?php
declare(strict_types=1);

namespace FlowDesk\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status = 200, public string $body = '', public array $headers = [])
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        // Only same-site relative targets are ever passed in; guard anyway.
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//')) {
            $to = '/';
        }
        return new self(303, '', ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
