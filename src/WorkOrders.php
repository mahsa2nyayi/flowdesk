<?php
declare(strict_types=1);

namespace FlowDesk;

use PDO;

final class ConflictException extends \RuntimeException
{
}

final class WorkOrders
{
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
    public const PER_PAGE = 15;

    public function __construct(private PDO $db)
    {
    }

    public function create(string $title, string $client, string $description, string $priority, int $userId): int
    {
        $now = Database::now();
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO work_orders (title, client, description, priority, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$title, $client, $description, $priority, $userId, $now, $now]);
            $id = (int) $this->db->lastInsertId();
            $this->db->prepare('UPDATE work_orders SET code = ? WHERE id = ?')->execute([sprintf('WO-%05d', $id), $id]);
            $this->log($id, $userId, 'created', null, null, 'new', null);
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?array
    {
        $s = $this->db->prepare(
            'SELECT o.*, u.display_name AS creator FROM work_orders o JOIN users u ON u.id = o.created_by WHERE o.id = ?'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return array{rows: list<array>, total: int, pages: int, page: int} */
    public function search(string $q, string $stage, int $page): array
    {
        $where  = [];
        $params = [];
        if ($q !== '') {
            // Escape LIKE wildcards so user input is matched literally.
            $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where[] = "(o.title LIKE ? ESCAPE '\\' OR o.client LIKE ? ESCAPE '\\' OR o.code LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }
        if ($stage !== '' && isset(Workflow::STAGES[$stage])) {
            $where[]  = 'o.stage = ?';
            $params[] = $stage;
        }
        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $c = $this->db->prepare("SELECT COUNT(*) FROM work_orders o$sql");
        $c->execute($params);
        $total = (int) $c->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, $page), $pages);

        $s = $this->db->prepare(
            "SELECT o.* FROM work_orders o$sql ORDER BY o.updated_at DESC, o.id DESC LIMIT " . self::PER_PAGE .
            ' OFFSET ' . (($page - 1) * self::PER_PAGE)
        );
        $s->execute($params);
        return ['rows' => $s->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return array<string, int> */
    public function countsByStage(): array
    {
        $counts = array_fill_keys(array_keys(Workflow::STAGES), 0);
        foreach ($this->db->query('SELECT stage, COUNT(*) AS n FROM work_orders GROUP BY stage') as $r) {
            $counts[$r['stage']] = (int) $r['n'];
        }
        return $counts;
    }

    /**
     * Move an order through the workflow atomically.
     * The UPDATE is conditional on the stage we read, so two people acting at once cannot both win.
     */
    public function transition(int $id, string $action, array $user, ?string $note): array
    {
        $this->db->beginTransaction();
        try {
            $order = $this->find($id) ?? throw new WorkflowException('Order not found.');
            $next  = Workflow::apply($order, $action, $user['role'], $note);

            $u = $this->db->prepare(
                'UPDATE work_orders SET stage = ?, held_from = ?, updated_at = ? WHERE id = ? AND stage = ?'
            );
            $u->execute([$next['stage'], $next['held_from'], Database::now(), $id, $order['stage']]);
            if ($u->rowCount() !== 1) {
                throw new ConflictException('Someone else changed this order. Reload and try again.');
            }
            $this->log($id, (int) $user['id'], 'transition', $action, $order['stage'], $next['stage'], $note);
            $this->db->commit();
            return $next;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function comment(int $id, int $userId, string $note): void
    {
        $this->log($id, $userId, 'comment', null, null, null, $note);
        $this->db->prepare('UPDATE work_orders SET updated_at = ? WHERE id = ?')->execute([Database::now(), $id]);
    }

    public function events(int $id): array
    {
        $s = $this->db->prepare(
            'SELECT e.*, u.display_name FROM events e JOIN users u ON u.id = e.user_id WHERE e.order_id = ? ORDER BY e.id DESC'
        );
        $s->execute([$id]);
        return $s->fetchAll();
    }

    private function log(int $orderId, int $userId, string $type, ?string $action, ?string $from, ?string $to, ?string $note): void
    {
        $this->db->prepare(
            'INSERT INTO events (order_id, user_id, type, action, from_stage, to_stage, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$orderId, $userId, $type, $action, $from, $to, $note !== null && trim($note) !== '' ? trim($note) : null, Database::now()]);
    }
}
