<?php
declare(strict_types=1);

namespace FlowDesk;

/** Thin wrapper over a session array (the real $_SESSION in production, a plain array in tests). */
final class Session
{
    /** @param null|callable():void $regenerate */
    public function __construct(private array &$data, private $regenerate = null)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        if ($this->regenerate !== null) {
            ($this->regenerate)();
        }
    }

    public function clear(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function flash(string $type, string $message): void
    {
        $this->data['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function pullFlash(): array
    {
        $f = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);
        return $f;
    }
}
