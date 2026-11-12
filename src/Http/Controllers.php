<?php
declare(strict_types=1);

namespace FlowDesk\Http;

use FlowDesk\{Auth, ConflictException, Csrf, Session, Users, Validator, View, WorkOrders, Workflow, WorkflowException};

/** Small, readable controllers. Every handler returns a Response; none of them echo or exit. */
final class Controllers
{
    public function __construct(
        private Auth $auth,
        private Users $users,
        private WorkOrders $orders,
        private Session $session,
        private Csrf $csrf,
        private View $view,
    ) {
    }

    private function page(string $tpl, array $vars = [], int $status = 200): Response
    {
        $shared = [
            'user'  => $this->auth->user(),
            'csrf'  => $this->csrf->token(),
            'flash' => $this->session->pullFlash(),
        ];
        return Response::html($this->view->render($tpl, $vars, $shared), $status);
    }

    // ---- auth ------------------------------------------------------------------------

    public function loginForm(Request $r): Response
    {
        if ($this->auth->user()) {
            return Response::redirect('/');
        }
        return $this->page('login', ['title' => 'Sign in', 'error' => null, 'username' => '']);
    }

    public function login(Request $r): Response
    {
        $username = $r->input('username');
        $res = $this->auth->attempt($username, $r->input('password'), $r->ip);
        if ($res['ok']) {
            return Response::redirect('/');
        }
        return $this->page('login', ['title' => 'Sign in', 'error' => $res['error'], 'username' => $username], 401);
    }

    public function logout(Request $r): Response
    {
        $this->auth->logout();
        return Response::redirect('/login');
    }

    // ---- orders ----------------------------------------------------------------------

    public function dashboard(Request $r): Response
    {
        $q     = mb_substr(trim($r->input('q')), 0, 100);
        $stage = $r->input('stage');
        $res   = $this->orders->search($q, $stage, (int) $r->input('page', '1'));
        return $this->page('dashboard', [
            'title' => 'Dashboard', 'q' => $q, 'stage' => $stage,
            'counts' => $this->orders->countsByStage(), 'result' => $res,
        ]);
    }

    public function newOrderForm(Request $r): Response
    {
        return $this->page('order_new', ['title' => 'New work order', 'errors' => [], 'old' => ['priority' => 'normal']]);
    }

    public function createOrder(Request $r): Response
    {
        $v = new Validator();
        $title  = $v->text('title', 'Title', $r->input('title'), 120);
        $client = $v->text('client', 'Client', $r->input('client'), 120);
        $desc   = $v->text('description', 'Description', $r->input('description'), 2000, false);
        $prio   = $v->choice('priority', 'Priority', $r->input('priority'), WorkOrders::PRIORITIES);
        if ($v->fails()) {
            return $this->page('order_new', [
                'title' => 'New work order', 'errors' => $v->errors(),
                'old' => ['title' => $title, 'client' => $client, 'description' => $desc, 'priority' => $prio],
            ], 422);
        }
        $id = $this->orders->create($title, $client, $desc, $prio, (int) $this->auth->user()['id']);
        $this->session->flash('success', 'Work order created.');
        return Response::redirect("/orders/$id");
    }

    public function showOrder(Request $r, array $p): Response
    {
        $order = $this->orders->find($p['id']);
        if (!$order) {
            return $this->notFound();
        }
        return $this->page('order_show', [
            'title' => $order['code'], 'order' => $order,
            'events' => $this->orders->events($order['id']),
            'actions' => Workflow::available($order['stage'], $this->auth->user()['role']),
        ]);
    }

    public function transition(Request $r, array $p): Response
    {
        $back = '/orders/' . $p['id'];
        try {
            $this->orders->transition($p['id'], $r->input('action'), $this->auth->user(), $r->input('note'));
            $this->session->flash('success', 'Order updated.');
        } catch (ConflictException | WorkflowException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect($back);
    }

    public function comment(Request $r, array $p): Response
    {
        if (!$this->orders->find($p['id'])) {
            return $this->notFound();
        }
        $note = trim($r->input('note'));
        if ($note === '' || mb_strlen($note) > 1000) {
            $this->session->flash('error', 'Comment must be 1-1000 characters.');
        } else {
            $this->orders->comment($p['id'], (int) $this->auth->user()['id'], $note);
        }
        return Response::redirect('/orders/' . $p['id']);
    }

    // ---- users (manager only) --------------------------------------------------------

    public function users(Request $r): Response
    {
        return $this->page('users', ['title' => 'Users', 'list' => $this->users->all(), 'errors' => [], 'old' => ['role' => 'intake']]);
    }

    public function createUser(Request $r): Response
    {
        $v = new Validator();
        $username = $v->username('username', $r->input('username'));
        $name     = $v->text('display_name', 'Name', $r->input('display_name'), 80);
        $role     = $v->choice('role', 'Role', $r->input('role'), Workflow::ROLES);
        $password = $v->password('password', $r->input('password'));
        if (!$v->fails() && $this->users->findByUsername($username)) {
            return $this->usersError(['username' => 'That username is already taken.'], $username, $name, $role);
        }
        if ($v->fails()) {
            return $this->usersError($v->errors(), $username, $name, $role);
        }
        $this->users->create($username, $name, $password, $role);
        $this->session->flash('success', "User $username created.");
        return Response::redirect('/users');
    }

    private function usersError(array $errors, string $username, string $name, string $role): Response
    {
        return $this->page('users', [
            'title' => 'Users', 'list' => $this->users->all(), 'errors' => $errors,
            'old' => ['username' => $username, 'display_name' => $name, 'role' => $role],
        ], 422);
    }

    public function toggleUser(Request $r, array $p): Response
    {
        $target = $this->users->find($p['id']);
        if (!$target) {
            return $this->notFound();
        }
        if ((int) $target['id'] === (int) $this->auth->user()['id']) {
            $this->session->flash('error', 'You cannot deactivate your own account.');
        } else {
            $this->users->setActive($target['id'], !(bool) $target['active']);
            $this->session->flash('success', 'User updated.');
        }
        return Response::redirect('/users');
    }

    // ---- errors ----------------------------------------------------------------------

    public function notFound(): Response
    {
        return $this->page('error', ['title' => 'Not found', 'code' => 404, 'message' => 'That page does not exist.'], 404);
    }

    public function error(int $code, string $message): Response
    {
        return $this->page('error', ['title' => "Error $code", 'code' => $code, 'message' => $message], $code);
    }
}
