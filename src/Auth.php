<?php
declare(strict_types=1);

namespace FlowDesk;

use PDO;

final class Auth
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;

    private static ?string $dummyHash = null;
    private ?array $current = null;
    private bool $resolved = false;

    public function __construct(private PDO $db, private Users $users, private Session $session)
    {
    }

    /** @return array{ok: bool, error?: string} */
    public function attempt(string $username, string $password, string $ip): array
    {
        $username = strtolower(trim($username));

        if ($this->recentFailures($username, $ip) >= self::MAX_FAILURES) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Please try again in 15 minutes.'];
        }

        $user = $this->users->findByUsername($username);
        // Always run a hash verification so response time does not reveal whether the user exists.
        $hash  = $user['password_hash'] ?? (self::$dummyHash ??= Users::hash('timing-equalisation'));
        $valid = password_verify($password, $hash) && $user !== null && (int) $user['active'] === 1;

        if (!$valid) {
            $this->db->prepare('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)')
                ->execute([$username, $ip, time()]);
            return ['ok' => false, 'error' => 'Invalid username or password.'];
        }

        $this->db->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?')->execute([$username, $ip]);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->users->updateHash((int) $user['id'], Users::hash($password));
        }

        $this->session->regenerate();               // prevents session fixation
        $this->session->set('user_id', (int) $user['id']);
        $this->current  = $user;
        $this->resolved = true;
        return ['ok' => true];
    }

    /** Re-read the user on every request so deactivation and role changes apply immediately. */
    public function user(): ?array
    {
        if (!$this->resolved) {
            $id = $this->session->get('user_id');
            $u  = is_int($id) ? $this->users->find($id) : null;
            $this->current  = ($u && (int) $u['active'] === 1) ? $u : null;
            $this->resolved = true;
        }
        return $this->current;
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->current  = null;
        $this->resolved = true;
    }

    private function recentFailures(string $username, string $ip): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at > ?');
        $s->execute([$username, $ip, time() - self::WINDOW_SECONDS]);
        return (int) $s->fetchColumn();
    }
}
