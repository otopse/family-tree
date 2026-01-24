<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

if (current_user()) {
  redirect('/account.php');
}

$errors = [];
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Neplatný bezpečnostný token. Skúste to prosím znova.';
  }

  $identifier = trim((string) ($_POST['identifier'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');

  if ($identifier === '') {
    $errors[] = 'Zadajte email alebo telefónne číslo.';
  }

  if ($password === '') {
    $errors[] = 'Zadajte heslo.';
  }

  if (!$errors) {
    $email = strtolower($identifier);
    $phone = normalize_phone($identifier);

    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email OR phone = :phone LIMIT 1');
    $stmt->execute([
      'email' => $email,
      'phone' => $phone,
    ]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
      $errors[] = 'Nesprávny email/telefón alebo heslo.';
    } else {
      if (!empty($user['password_hash']) && password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = db()->prepare('UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id');
        $rehash->execute([
          'hash' => password_hash($password, PASSWORD_DEFAULT),
          'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
          'id' => $user['id'],
        ]);
      }

      if (empty($user['email_verified_at'])) {
        $_SESSION['pending_user_id'] = (int) $user['id'];
        flash('info', 'Email ešte nie je overený. Skontrolujte si poštu alebo požiadajte o nový link.');
        redirect('/verify-email.php?email=' . urlencode($user['email']));
      }

      if (empty($user['phone_verified_at'])) {
        $_SESSION['pending_user_id'] = (int) $user['id'];
        flash('info', 'Telefón ešte nie je overený. Zadajte SMS kód.');
        redirect('/verify-phone.php?email=' . urlencode($user['email']));
      }

      session_regenerate_id(true);
      $_SESSION['user_id'] = (int) $user['id'];
      $update = db()->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id');
      $update->execute([
        'last_login_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        'id' => $user['id'],
      ]);
      redirect('/account.php');
    }
  }
}

render_header('Prihlásenie');
?>
  <div class="container">
    <div class="auth-card">
      <h1 class="section-title">Prihlásenie</h1>
      <?php render_flash(); ?>
      <?php if ($errors): ?>
        <div class="alert alert-error">
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <form method="post" action="/login.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label for="identifier">Email alebo telefón</label>
          <input class="form-control" type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" required>
        </div>
        <div class="form-group">
          <label for="password">Heslo</label>
          <input class="form-control" type="password" id="password" name="password" required>
        </div>
        <button class="btn-primary btn-large" type="submit">Prihlásiť sa</button>
        <div class="auth-links">
          <span>Nemáte účet?</span>
          <a href="/register.php">Zaregistrovať sa</a>
        </div>
      </form>
      <div class="auth-links">
        <a href="/verify-email.php">Znovu poslať overovací email</a>
        <a href="/verify-phone.php">Znovu poslať SMS kód</a>
      </div>
    </div>
  </div>
<?php
render_footer();
