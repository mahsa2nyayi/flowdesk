<?php
declare(strict_types=1);

namespace FlowDesk;

/** Collects field errors; keeps controllers free of repetitive if-chains. */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function text(string $field, string $label, string $value, int $max, bool $required = true): string
    {
        $value = trim($value);
        $len   = mb_strlen($value);
        if ($required && $len === 0) {
            $this->errors[$field] = "$label is required.";
        } elseif ($len > $max) {
            $this->errors[$field] = "$label must be at most $max characters.";
        }
        return $value;
    }

    /** @param array<string, string> $allowed */
    public function choice(string $field, string $label, string $value, array $allowed): string
    {
        if (!isset($allowed[$value])) {
            $this->errors[$field] = "$label is not valid.";
        }
        return $value;
    }

    public function username(string $field, string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $value)) {
            $this->errors[$field] = 'Username must be 3-32 characters: letters, digits, dot, dash or underscore.';
        }
        return $value;
    }

    public function password(string $field, string $value): string
    {
        $len = strlen($value);
        if ($len < Users::MIN_PASSWORD || $len > Users::MAX_PASSWORD) {
            $this->errors[$field] = sprintf('Password must be %d-%d characters.', Users::MIN_PASSWORD, Users::MAX_PASSWORD);
        }
        return $value;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
