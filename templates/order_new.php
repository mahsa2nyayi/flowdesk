<section class="card narrow wide">
  <h1>New work order</h1>
  <form method="post" action="/orders" class="form" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>Title
      <input name="title" value="<?= e($old['title'] ?? '') ?>" maxlength="120" required>
      <?php if (isset($errors['title'])): ?><span class="field-error"><?= e($errors['title']) ?></span><?php endif; ?>
    </label>
    <label>Client
      <input name="client" value="<?= e($old['client'] ?? '') ?>" maxlength="120" required>
      <?php if (isset($errors['client'])): ?><span class="field-error"><?= e($errors['client']) ?></span><?php endif; ?>
    </label>
    <label>Priority
      <select name="priority">
        <?php foreach (\FlowDesk\WorkOrders::PRIORITIES as $k => $l): ?>
          <option value="<?= e($k) ?>"<?= ($old['priority'] ?? 'normal') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['priority'])): ?><span class="field-error"><?= e($errors['priority']) ?></span><?php endif; ?>
    </label>
    <label>Description
      <textarea name="description" rows="5" maxlength="2000"><?= e($old['description'] ?? '') ?></textarea>
      <?php if (isset($errors['description'])): ?><span class="field-error"><?= e($errors['description']) ?></span><?php endif; ?>
    </label>
    <div class="row"><button class="btn primary" type="submit">Create order</button><a class="btn ghost" href="/">Cancel</a></div>
  </form>
</section>
