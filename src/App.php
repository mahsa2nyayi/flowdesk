<?php
declare(strict_types=1);

namespace FlowDesk;

use FlowDesk\Http\{Controllers, Request, Response, Router};
use PDO;

/** Wires dependencies, applies cross-cutting security rules, and dispatches. Testable without a web server. */
final class App
{
    private Router $router;
    private Auth $auth;
    private Csrf $csrf;
    private Controllers $c;

    /** @param array{debug?: bool, https?: bool} $config */
    public function __construct(PDO $db, array &$sessionData, private array $config = [], ?callable $regenerate = null)
    {
        $session    = new Session($sessionData, $regenerate);
        $users      = new Users($db);
        $this->auth = new Auth($db, $users, $session);
        $this->csrf = new Csrf($session);
        $this->c    = new Controllers($this->auth, $users, new WorkOrders($db), $session, $this->csrf, new View(__DIR__ . '/../templates'));
        $this->router = new Router();
        $this->routes();
    }

    private function routes(): void
    {
        $r = $this->router;
        $any     = ['auth' => true];
        $creator = ['auth' => true, 'roles' => ['manager', 'intake']];
        $admin   = ['auth' => true, 'roles' => ['manager']];

        $r->add('GET',  '/login',  fn($q) => $this->c->loginForm($q));
        $r->add('POST', '/login',  fn($q) => $this->c->login($q));
        $r->add('POST', '/logout', fn($q) => $this->c->logout($q), $any);

        $r->add('GET',  '/',                         fn($q) => $this->c->dashboard($q), $any);
        $r->add('GET',  '/orders/new',               fn($q) => $this->c->newOrderForm($q), $creator);
        $r->add('POST', '/orders',                   fn($q) => $this->c->createOrder($q), $creator);
        $r->add('GET',  '/orders/{id}',              fn($q, $p) => $this->c->showOrder($q, $p), $any);
        $r->add('POST', '/orders/{id}/transition',   fn($q, $p) => $this->c->transition($q, $p), $any);
        $r->add('POST', '/orders/{id}/comment',      fn($q, $p) => $this->c->comment($q, $p), $any);

        $r->add('GET',  '/users',                    fn($q) => $this->c->users($q), $admin);
        $r->add('POST', '/users',                    fn($q) => $this->c->createUser($q), $admin);
        $r->add('POST', '/users/{id}/toggle',        fn($q, $p) => $this->c->toggleUser($q, $p), $admin);
    }

    public function handle(Request $req): Response
    {
        try {
            $res = $this->dispatch($req);
        } catch (\Throwable $e) {
            error_log('[flowdesk] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $msg = !empty($this->config['debug']) ? $e->getMessage() : 'Something went wrong.';
            $res = Response::html('<!doctype html><title>Error</title><h1>500</h1><p>' . e($msg) . '</p>', 500);
        }
        return $this->withSecurityHeaders($res);
    }

    private function dispatch(Request $req): Response
    {
        $m = $this->router->match($req->method, $req->path);
        if ($m === 404) {
            return $this->c->notFound();
        }
        if ($m === 405) {
            return $this->c->error(405, 'Method not allowed.');
        }
        [$handler, $params, $opts] = $m;

        // CSRF applies to every state-changing request, including login.
        if ($req->method === 'POST' && !$this->csrf->verify($req->post[Csrf::FIELD] ?? null)) {
            return $this->c->error(419, 'Your session expired. Reload the page and try again.');
        }

        if (!empty($opts['auth'])) {
            $user = $this->auth->user();
            if ($user === null) {
                return Response::redirect('/login');   // returns: nothing after this can run
            }
            if (isset($opts['roles']) && !in_array($user['role'], $opts['roles'], true)) {
                return $this->c->error(403, 'You do not have access to this page.');
            }
        }
        return $handler($req, $params);
    }

    private function withSecurityHeaders(Response $r): Response
    {
        $r->headers += [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Cache-Control'           => 'no-store',
        ];
        if (!empty($this->config['https'])) {
            $r->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $r;
    }
}
