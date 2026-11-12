<?php
declare(strict_types=1);

/** HTML-escape for output. Every template value goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
