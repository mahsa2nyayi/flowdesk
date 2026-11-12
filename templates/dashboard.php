<?php use FlowDesk\Workflow; ?>
<h1>Dashboard</h1>

<section class="stats" aria-label="Orders by stage">
  <?php foreach (Workflow::STAGES as $key => $label): ?>
    <a class="stat stage-<?= e($key) ?><?= $stage === $key ? ' active' : '' ?>" href="/?stage=<?= e($key) ?>">
      <span class="stat-n"><?= (int) $counts[$key] ?></span>
      <span class="stat-l"><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
</section>

<form class="filters" method="get" action="/">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search code, title or client" aria-label="Search">
  <select name="stage" aria-label="Stage">
    <option value="">All stages</option>
    <?php foreach (Workflow::STAGES as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $stage === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Filter</button>
  <?php if ($q !== '' || $stage !== ''): ?><a class="btn ghost" href="/">Clear</a><?php endif; ?>
</form>

<div class="card flush">
<?php if (!$result['rows']): ?>
  <p class="empty">No work orders match your filters.</p>
<?php else: ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Code</th><th>Title</th><th>Client</th><th>Priority</th><th>Stage</th><th>Updated (UTC)</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $o): ?>
      <tr>
        <td><a href="/orders/<?= (int) $o['id'] ?>"><?= e($o['code']) ?></a></td>
        <td><?= e($o['title']) ?></td>
        <td><?= e($o['client']) ?></td>
        <td><span class="badge prio-<?= e($o['priority']) ?>"><?= e(\FlowDesk\WorkOrders::PRIORITIES[$o['priority']]) ?></span></td>
        <td><span class="badge stage-<?= e($o['stage']) ?>"><?= e(Workflow::STAGES[$o['stage']]) ?></span></td>
        <td class="muted"><?= e(substr($o['updated_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<?php if ($result['pages'] > 1): ?>
<nav class="pager" aria-label="Pagination">
  <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
    <a class="<?= $i === $result['page'] ? 'current' : '' ?>" href="/?<?= e(http_build_query(['q' => $q, 'stage' => $stage, 'page' => $i])) ?>"><?= $i ?></a>
  <?php endfor; ?>
  <span class="muted"><?= (int) $result['total'] ?> orders</span>
</nav>
<?php endif; ?>
