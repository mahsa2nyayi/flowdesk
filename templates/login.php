<section class="card narrow">
  <h1>Sign in</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login" class="form">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label>Username
      <input name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input name="password" type="password" autocomplete="current-password" required>
    </label>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
</section>
