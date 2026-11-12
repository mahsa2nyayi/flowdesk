<h1>Users</h1>
<div class="grid">
  <section class="card flush">
    <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $u): ?>
        <tr>
          <td><strong><?= e($u['display_name']) ?></strong><br><small class="muted"><?= e($u['username']) ?></small></td>
          <td><?= e(\FlowDesk\Workflow::ROLES[$u['role']]) ?></td>
          <td><span class="badge <?= $u['active'] ? 'stage-done' : 'stage-hold' ?>"><?= $u['active'] ? 'Active' : 'Disabled' ?></span></td>
          <td>
            <?php if ((int) $u['id'] !== (int) $user['id']): ?>
            <form method="post" action="/users/<?= (int) $u['id'] ?>/toggle">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <button class="btn ghost small" type="submit"><?= $u['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="card">
    <h2>Add user</h2>
    <form method="post" action="/users" class="form" novalidate>
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label>Username
        <input name="username" value="<?= e($old['username'] ?? '') ?>" autocomplete="off" required>
        <?php if (isset($errors['username'])): ?><span class="field-error"><?= e($errors['username']) ?></span><?php endif; ?>
      </label>
      <label>Full name
        <input name="display_name" value="<?= e($old['display_name'] ?? '') ?>" required>
        <?php if (isset($errors['display_name'])): ?><span class="field-error"><?= e($errors['display_name']) ?></span><?php endif; ?>
      </label>
      <label>Role
        <select name="role">
          <?php foreach (\FlowDesk\Workflow::ROLES as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= ($old['role'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Password <span class="muted">(10–72 characters)</span>
        <input name="password" type="password" autocomplete="new-password" required>
        <?php if (isset($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
      </label>
      <button class="btn primary" type="submit">Create user</button>
    </form>
  </section>
</div>
