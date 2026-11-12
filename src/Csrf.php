<?php
declare(strict_types=1);

namespace FlowDesk;

final class Csrf
{
    public const FIELD = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->get('csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $this->session->set('csrf', $t);
        }
        return $t;
    }

    public function verify(?string $submitted): bool
    {
        $t = $this->session->get('csrf');
        return is_string($t) && is_string($submitted) && $submitted !== '' && hash_equals($t, $submitted);
    }
}
