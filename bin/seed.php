<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use FlowDesk\{Database, Users, WorkOrders, Workflow};

if (getenv('APP_ENV') === 'production') {
    fwrite(STDERR, "Refusing to seed demo data when APP_ENV=production.\n");
    exit(1);
}

$dsn = getenv('DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/var/flowdesk.sqlite';
$pdo = Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASS') ?: null);
Database::install($pdo);

if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    fwrite(STDERR, "Database already has users; not seeding.\n");
    exit(1);
}

// Demo-only credentials. Never use these outside a local environment.
$demoPassword = getenv('DEMO_PASSWORD') ?: 'demo-password-123';
$users  = new Users($pdo);
$orders = new WorkOrders($pdo);
$ids = [];
foreach ([
    ['manager', 'Maryam Hosseini', 'manager'],
    ['intake',  'Nima Karimi',     'intake'],
    ['surveyor', 'Sara Ahmadi',    'surveyor'],
    ['drafter', 'Omid Rahimi',     'drafter'],
] as [$u, $n, $r]) {
    $ids[$r] = $users->create($u, $n, $demoPassword, $r);
}
$byRole = fn(string $role) => ['id' => $ids[$role], 'role' => $role];

mt_srand(42);
$clients = ['Northwind Estates', 'Blue Harbor Co.', 'Cedar & Pine LLC', 'Lakeside Council', 'Atlas Logistics', 'Greenfield Farms'];
$titles  = ['Boundary check', 'Site layout update', 'Drainage plan', 'Access road design', 'Plot subdivision', 'Elevation report', 'Utility mapping', 'Foundation survey'];
$prios   = array_keys(WorkOrders::PRIORITIES);
// Each path is the list of actions to replay, so the history in the database is realistic and valid.
$paths = [
    [], ['start_survey'], ['start_survey', 'finish_survey'], ['start_survey', 'finish_survey', 'submit_review'],
    ['start_survey', 'finish_survey', 'submit_review', 'approve'], ['start_survey', 'hold'],
    ['start_survey', 'finish_survey', 'submit_review', 'reject'],
];
$roleFor = ['start_survey' => 'surveyor', 'finish_survey' => 'surveyor', 'submit_review' => 'drafter',
            'approve' => 'manager', 'reject' => 'manager', 'hold' => 'manager', 'resume' => 'manager'];
$notes   = ['reject' => 'Please recheck the northern boundary dimensions.', 'hold' => 'Waiting for client documents.'];

for ($i = 0; $i < 34; $i++) {
    $id = $orders->create($titles[$i % 8] . ' #' . (100 + $i), $clients[$i % 6],
        'Routine request received from the client. Details to be confirmed on site.', $prios[mt_rand(0, 3)], $ids['intake']);
    foreach ($paths[$i % count($paths)] as $action) {
        $orders->transition($id, $action, $byRole($roleFor[$action]), $notes[$action] ?? null);
    }
}
$orders->comment(1, $ids['manager'], 'Client called: please prioritise this one.');
echo "Seeded 4 users (password: $demoPassword) and 34 work orders.\n";
echo "Users: manager, intake, surveyor, drafter\n";
