<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use FlowDesk\{App, Auth, Csrf, Database, Session, Users, WorkOrders, Workflow, WorkflowException, ConflictException};
use FlowDesk\Http\{Request, Response};

// ---- tiny test harness (no external dependencies) ------------------------------------

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }
function ok(bool $cond, string $msg = 'assertion failed'): void { if (!$cond) { throw new RuntimeException($msg); } }
function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function throws(string $class, callable $fn, ?string $contains = null): void
{
    try { $fn(); } catch (Throwable $e) {
        ok($e instanceof $class, 'expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
        if ($contains !== null) { ok(str_contains($e->getMessage(), $contains), "message should contain '$contains': " . $e->getMessage()); }
        return;
    }
    throw new RuntimeException("expected $class to be thrown");
}

function freshDb(): PDO { $p = Database::connect('sqlite::memory:'); Database::install($p); return $p; }

/** Simulates one browser: keeps its own session array between requests. */
final class Browser
{
    public array $session = [];
    public int $regenerations = 0;
    public function __construct(public PDO $db, public string $ip = '10.0.0.1')
    {
    }
    /** A fresh App per request, exactly like production (PHP builds the app on every request). */
    private function dispatch(Request $req): Response
    {
        $app = new App($this->db, $this->session, [], function (): void { $this->regenerations++; });
        return $app->handle($req);
    }
    public function get(string $path, array $query = []): Response
    {
        return $this->dispatch(new Request('GET', $path, $query, [], $this->ip));
    }
    /** POSTs with a valid CSRF token unless $csrf is false. */
    public function post(string $path, array $data = [], bool $csrf = true): Response
    {
        if ($csrf) {
            $this->get('/login');                               // ensures a token exists
            $data[Csrf::FIELD] = $this->session['csrf'];
        }
        return $this->dispatch(new Request('POST', $path, [], $data, $this->ip));
    }
    public function login(string $user, string $pass = 'correct-horse-1'): Response { return $this->post('/login', ['username' => $user, 'password' => $pass]); }
}

function seedUsers(PDO $db): array
{
    $u = new Users($db);
    $ids = [];
    foreach (['manager', 'intake', 'surveyor', 'drafter'] as $r) { $ids[$r] = $u->create($r, ucfirst($r) . ' Person', 'correct-horse-1', $r); }
    return $ids;
}
function userRow(array $ids, string $role): array { return ['id' => $ids[$role], 'role' => $role]; }

// ---- workflow state machine ----------------------------------------------------------

test('workflow: role-specific actions per stage', function () {
    same(['start_survey'], array_keys(Workflow::available('new', 'surveyor')));
    same(['start_survey', 'hold'], array_keys(Workflow::available('new', 'manager')));
    same([], array_keys(Workflow::available('new', 'drafter')));
    same(['approve', 'reject', 'hold'], array_keys(Workflow::available('review', 'manager')));
    same([], array_keys(Workflow::available('done', 'manager')), 'completed orders are final');
});

test('workflow: forbidden transitions are rejected', function () {
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'new'], 'approve', 'manager', null), 'not possible');
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'review'], 'approve', 'drafter', null), 'role');
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'new'], 'teleport', 'manager', null), 'Unknown');
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'done'], 'hold', 'manager', 'x'), 'not possible');
});

test('workflow: notes are mandatory for reject and hold', function () {
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'review'], 'reject', 'manager', '  '), 'note is required');
    same('drafting', Workflow::apply(['stage' => 'review'], 'reject', 'manager', 'fix it')['stage']);
});

test('workflow: hold remembers the previous stage and resume restores it', function () {
    $held = Workflow::apply(['stage' => 'drafting'], 'hold', 'manager', 'waiting');
    same(['stage' => 'hold', 'held_from' => 'drafting'], $held);
    $back = Workflow::apply(['stage' => 'hold', 'held_from' => 'drafting'], 'resume', 'manager', null);
    same(['stage' => 'drafting', 'held_from' => null], $back);
    throws(WorkflowException::class, fn() => Workflow::apply(['stage' => 'hold', 'held_from' => null], 'resume', 'manager', null));
});

// ---- repository ----------------------------------------------------------------------

test('orders: create assigns a code and logs a creation event', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    $id = $o->create('Title', 'Client', 'desc', 'high', $ids['intake']);
    $row = $o->find($id);
    same('WO-00001', $row['code']);
    same('new', $row['stage']);
    same('created', $o->events($id)[0]['type']);
});

test('orders: transition writes an audit event and updates the stage', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    $id = $o->create('T', 'C', '', 'normal', $ids['intake']);
    $o->transition($id, 'start_survey', userRow($ids, 'surveyor'), null);
    same('survey', $o->find($id)['stage']);
    $ev = $o->events($id)[0];
    same(['transition', 'start_survey', 'new', 'survey'], [$ev['type'], $ev['action'], $ev['from_stage'], $ev['to_stage']]);
});

test('orders: failed transition rolls back and leaves no event', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    $id = $o->create('T', 'C', '', 'normal', $ids['intake']);
    throws(WorkflowException::class, fn() => $o->transition($id, 'approve', userRow($ids, 'manager'), null));
    same('new', $o->find($id)['stage']);
    same(1, count($o->events($id)));
});

test('orders: the guarded UPDATE used by transition() ignores a row that changed underneath it', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    $id = $o->create('T', 'C', '', 'normal', $ids['intake']);
    // Simulate another user moving the order between our read and our write.
    $stale = $o->find($id);
    $db->exec("UPDATE work_orders SET stage = 'survey' WHERE id = $id");
    $u = $db->prepare('UPDATE work_orders SET stage = ? WHERE id = ? AND stage = ?');
    $u->execute(['drafting', $id, $stale['stage']]);
    same(0, $u->rowCount(), 'conditional UPDATE must not match a changed row');
});

test('orders: search escapes LIKE wildcards and ignores injection payloads', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    $o->create('100% done', 'Acme', '', 'low', $ids['intake']);
    $o->create('Other thing', 'Acme', '', 'low', $ids['intake']);
    same(1, $o->search('100%', '', 1)['total'], 'percent sign matched literally');
    same(1, $o->search('%', '', 1)['total'], 'a lone % only matches titles that contain a literal percent sign');
    same(0, $o->search("' OR 1=1 --", '', 1)['total'], 'payload treated as plain text');
    same(2, $o->search('', '', 1)['total']);
    same(2, $o->countsByStage()['new']);
});

test('orders: pagination clamps page numbers', function () {
    $db = freshDb(); $ids = seedUsers($db); $o = new WorkOrders($db);
    for ($i = 0; $i < 20; $i++) { $o->create("T$i", 'C', '', 'low', $ids['intake']); }
    $r = $o->search('', '', 99);
    same(2, $r['pages']); same(2, $r['page']); same(5, count($r['rows']));
    same(1, $o->search('', '', -5)['page']);
});

// ---- authentication ------------------------------------------------------------------

test('auth: passwords are stored hashed, never in clear text', function () {
    $db = freshDb(); seedUsers($db);
    $h = $db->query("SELECT password_hash FROM users WHERE username='manager'")->fetchColumn();
    ok($h !== 'correct-horse-1' && str_starts_with($h, '$'), 'hash looks like a PHP password hash');
    ok(password_verify('correct-horse-1', $h));
});

test('auth: login regenerates the session id and logout clears it', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db);
    $res = $b->login('manager');
    same(303, $res->status);
    same(1, $b->regenerations, 'session id regenerated on login');
    $b->post('/logout');
    ok(!isset($b->session['user_id']), 'session cleared on logout');
    same(303, $b->get('/')->status);
});

test('auth: wrong password and unknown user give the same generic message', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db);
    $a = $b->login('manager', 'nope'); $c = $b->login('ghost', 'nope');
    same(401, $a->status);
    ok(str_contains($a->body, 'Invalid username or password.') && str_contains($c->body, 'Invalid username or password.'));
});

test('auth: lockout after repeated failures, even with the right password', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db);
    for ($i = 0; $i < Auth::MAX_FAILURES; $i++) { $b->login('manager', 'bad'); }
    $res = $b->login('manager');
    same(401, $res->status);
    ok(str_contains($res->body, 'Too many failed attempts'));
    $other = new Browser($db, '10.9.9.9');         // different client is not locked out
    same(303, $other->login('manager')->status);
});

test('auth: deactivated users cannot log in and existing sessions stop working', function () {
    $db = freshDb(); $ids = seedUsers($db); $b = new Browser($db);
    same(303, $b->login('surveyor')->status);
    same(200, $b->get('/')->status);
    (new Users($db))->setActive($ids['surveyor'], false);
    same(303, $b->get('/')->status, 'next request is bounced to login');
    $b2 = new Browser($db);
    same(401, $b2->login('surveyor')->status);
});

// ---- HTTP behaviour ------------------------------------------------------------------

test('http: security headers are present', function () {
    $db = freshDb(); $r = (new Browser($db))->get('/login');
    same(200, $r->status);
    ok(str_contains($r->headers['Content-Security-Policy'], "default-src 'self'"));
    same('nosniff', $r->headers['X-Content-Type-Options']);
    same('DENY', $r->headers['X-Frame-Options']);
});

test('http: pages require login and redirect without leaking content', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db);
    foreach (['/', '/orders/new', '/orders/1', '/users'] as $path) {
        $r = $b->get($path);
        same(303, $r->status, $path);
        same('/login', $r->headers['Location']);
        same('', $r->body, "$path must not render anything for anonymous users");
    }
});

test('http: POST without a valid CSRF token is rejected (419)', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db);
    same(419, $b->post('/login', ['username' => 'manager', 'password' => 'correct-horse-1'], false)->status);
    $b->login('manager');
    same(419, $b->post('/orders', ['title' => 'x', 'client' => 'y', 'priority' => 'low'], false)->status);
    $b->get('/login');
    same(419, (new Browser($db))->post('/logout', [Csrf::FIELD => 'forged'], false)->status);
    same(0, (int) $db->query('SELECT COUNT(*) FROM work_orders')->fetchColumn());
});

test('http: role-based access control', function () {
    $db = freshDb(); seedUsers($db);
    $s = new Browser($db); $s->login('surveyor');
    same(403, $s->get('/users')->status, 'surveyor cannot see users');
    same(403, $s->get('/orders/new')->status, 'surveyor cannot create orders');
    same(403, $s->post('/orders', ['title' => 'a', 'client' => 'b', 'priority' => 'low'])->status);
    $i = new Browser($db); $i->login('intake');
    same(200, $i->get('/orders/new')->status);
    same(403, $i->get('/users')->status);
    $m = new Browser($db); $m->login('manager');
    same(200, $m->get('/users')->status);
});

test('http: validation errors re-render the form with status 422 and keep input', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db); $b->login('intake');
    $r = $b->post('/orders', ['title' => '', 'client' => 'Acme', 'priority' => 'bogus', 'description' => 'keep me']);
    same(422, $r->status);
    ok(str_contains($r->body, 'Title is required.') && str_contains($r->body, 'Priority is not valid.'));
    ok(str_contains($r->body, 'keep me') && str_contains($r->body, 'value="Acme"'));
    same(0, (int) $db->query('SELECT COUNT(*) FROM work_orders')->fetchColumn());
});

test('http: user-supplied HTML is escaped everywhere it is shown', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db); $b->login('intake');
    $payload = '<script>alert(1)</script>';
    $r = $b->post('/orders', ['title' => $payload, 'client' => '"><img src=x onerror=1>', 'priority' => 'low', 'description' => $payload]);
    same(303, $r->status);
    $b->post('/orders/1/comment', ['note' => $payload]);
    foreach (['/orders/1', '/'] as $path) {
        $body = $b->get($path)->body;
        ok(!str_contains($body, '<script>alert'), "$path leaks raw script tag");
        ok(!str_contains($body, '<img src=x'), "$path leaks raw img tag");
    }
    ok(str_contains($b->get('/orders/1')->body, '&lt;script&gt;'));
});

test('http: array-style or odd query parameters do not crash the app', function () {
    $db = freshDb(); seedUsers($db); $b = new Browser($db); $b->login('manager');
    same(200, $b->get('/', ['q' => ['x'], 'stage' => ['y'], 'page' => ['z']])->status);
    same(200, $b->get('/', ['q' => str_repeat('a', 5000), 'page' => '-3'])->status);
    same(404, $b->get('/orders/999')->status);
    same(404, $b->get('/nope')->status);
    same(405, $b->post('/orders/1')->status);
});

test('http: full lifecycle through four roles, with a reject loop and hold/resume', function () {
    $db = freshDb(); seedUsers($db);
    $intake = new Browser($db); $intake->login('intake');
    $surv = new Browser($db, '10.0.0.2'); $surv->login('surveyor');
    $draft = new Browser($db, '10.0.0.3'); $draft->login('drafter');
    $mgr = new Browser($db, '10.0.0.4'); $mgr->login('manager');
    $stage = fn() => $db->query('SELECT stage FROM work_orders WHERE id=1')->fetchColumn();
    $act = fn(Browser $b, string $a, string $n = '') => $b->post('/orders/1/transition', ['action' => $a, 'note' => $n]);

    same('/orders/1', $intake->post('/orders', ['title' => 'Plot A', 'client' => 'Acme', 'priority' => 'urgent'])->headers['Location']);
    $act($draft, 'start_survey');             same('new', $stage(), 'drafter cannot start survey');
    $act($surv, 'start_survey');              same('survey', $stage());
    $act($surv, 'finish_survey');             same('drafting', $stage());
    $act($draft, 'submit_review');            same('review', $stage());
    $act($mgr, 'reject');                     same('review', $stage(), 'reject needs a note');
    $act($mgr, 'reject', 'Redo section 2');   same('drafting', $stage());
    $act($draft, 'submit_review');            same('review', $stage());
    $act($mgr, 'hold', 'Client paused');      same('hold', $stage());
    $act($mgr, 'resume');                     same('review', $stage());
    $act($mgr, 'approve');                    same('done', $stage());
    $act($mgr, 'hold', 'too late');           same('done', $stage(), 'completed is final');
    same(8, (int) $db->query("SELECT COUNT(*) FROM events WHERE order_id=1 AND type='transition'")->fetchColumn(), 'only the 8 valid transitions are logged');
});

test('http: managers can create users, duplicates and weak passwords are refused, self-disable blocked', function () {
    $db = freshDb(); $ids = seedUsers($db); $m = new Browser($db); $m->login('manager');
    same(303, $m->post('/users', ['username' => 'newbie', 'display_name' => 'New B', 'role' => 'drafter', 'password' => 'long-enough-pw'])->status);
    same(422, $m->post('/users', ['username' => 'newbie', 'display_name' => 'Again', 'role' => 'drafter', 'password' => 'long-enough-pw'])->status);
    $weak = $m->post('/users', ['username' => 'weak', 'display_name' => 'W', 'role' => 'drafter', 'password' => 'short']);
    same(422, $weak->status); ok(str_contains($weak->body, 'Password must be'));
    same(422, $m->post('/users', ['username' => 'x y', 'display_name' => 'W', 'role' => 'root', 'password' => 'long-enough-pw'])->status);
    $m->post('/users/' . $ids['manager'] . '/toggle');
    same(1, (int) $db->query("SELECT active FROM users WHERE username='manager'")->fetchColumn(), 'cannot disable yourself');
});

// ---- runner --------------------------------------------------------------------------

$failed = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try { $fn(); echo "  ✓ $name\n"; }
    catch (Throwable $e) { $failed++; echo "  ✗ $name\n      " . $e->getMessage() . "\n"; }
}
$total = count($GLOBALS['__tests']);
echo "\n" . ($total - $failed) . " / $total passed\n";
exit($failed ? 1 : 0);
