<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · FlowDesk</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="top">
  <div class="top-inner">
    <a class="brand" href="/"><span class="logo" aria-hidden="true"></span>FlowDesk</a>
    <?php if ($user): ?>
    <nav class="nav">
      <a href="/">Dashboard</a>
      <?php if (in_array($user['role'], ['manager', 'intake'], true)): ?><a href="/orders/new">New order</a><?php endif; ?>
      <?php if ($user['role'] === 'manager'): ?><a href="/users">Users</a><?php endif; ?>
    </nav>
    <form class="who" method="post" action="/logout">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <span><?= e($user['display_name']) ?> <small class="muted">· <?= e(\FlowDesk\Workflow::ROLES[$user['role']]) ?></small></span>
      <button class="btn ghost small" type="submit">Sign out</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
  <?php foreach ($flash as $f): ?>
    <div class="alert <?= $f['type'] === 'error' ? 'alert-error' : 'alert-ok' ?>" role="status"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content /* already-rendered template output; every dynamic value inside was escaped with e() */ ?>
</main>
</body>
</html>
