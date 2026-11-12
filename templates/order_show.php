<?php use FlowDesk\Workflow; ?>
<p class="crumb"><a href="/">← Dashboard</a></p>
<div class="head">
  <h1><?= e($order['code']) ?> <span class="muted">·</span> <?= e($order['title']) ?></h1>
  <span class="badge big stage-<?= e($order['stage']) ?>"><?= e(Workflow::STAGES[$order['stage']]) ?></span>
</div>

<div class="grid">
  <section class="card">
    <h2>Details</h2>
    <dl class="kv">
      <dt>Client</dt><dd><?= e($order['client']) ?></dd>
      <dt>Priority</dt><dd><span class="badge prio-<?= e($order['priority']) ?>"><?= e(\FlowDesk\WorkOrders::PRIORITIES[$order['priority']]) ?></span></dd>
      <dt>Created by</dt><dd><?= e($order['creator']) ?></dd>
      <dt>Created</dt><dd><?= e($order['created_at']) ?> UTC</dd>
      <?php if ($order['stage'] === 'hold' && $order['held_from']): ?>
        <dt>Held from</dt><dd><?= e(Workflow::STAGES[$order['held_from']] ?? '') ?></dd>
      <?php endif; ?>
    </dl>
    <?php if ($order['description'] !== ''): ?><p class="desc"><?= nl2br(e($order['description'])) ?></p><?php endif; ?>
  </section>

  <section class="card">
    <h2>Actions</h2>
    <form method="post" action="/orders/<?= (int) $order['id'] ?>/transition" class="form">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <?php if ($actions): ?>
        <label>Note <span class="muted">(required for <?= e(implode(', ', array_map(fn($a) => $a['label'], array_filter($actions, fn($a) => $a['note']))) ?: 'none') ?>)</span>
          <textarea name="note" rows="2" maxlength="1000"></textarea>
        </label>
        <div class="row">
          <?php foreach ($actions as $key => $a): ?>
            <button class="btn <?= e($a['style']) ?>" type="submit" name="action" value="<?= e($key) ?>"><?= e($a['label']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="muted">No actions available to your role at this stage.</p>
      <?php endif; ?>
    </form>
  </section>
</div>

<section class="card">
  <h2>Activity</h2>
  <form method="post" action="/orders/<?= (int) $order['id'] ?>/comment" class="form inline">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input name="note" placeholder="Add a comment…" maxlength="1000" aria-label="Comment" required>
    <button class="btn" type="submit">Comment</button>
  </form>
  <ol class="timeline">
  <?php foreach ($events as $ev): ?>
    <li class="ev ev-<?= e($ev['type']) ?>">
      <div class="ev-head">
        <strong><?= e($ev['display_name']) ?></strong>
        <?php if ($ev['type'] === 'created'): ?>created the order
        <?php elseif ($ev['type'] === 'transition'): ?>moved it from
          <span class="badge stage-<?= e($ev['from_stage']) ?>"><?= e(Workflow::STAGES[$ev['from_stage']] ?? '') ?></span> to
          <span class="badge stage-<?= e($ev['to_stage']) ?>"><?= e(Workflow::STAGES[$ev['to_stage']] ?? '') ?></span>
        <?php else: ?>commented<?php endif; ?>
        <span class="muted"><?= e($ev['created_at']) ?> UTC</span>
      </div>
      <?php if ($ev['note'] !== null): ?><p class="ev-note"><?= nl2br(e($ev['note'])) ?></p><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ol>
</section>
