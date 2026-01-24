<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$errors = [];
$token = trim((string) ($_GET['token'] ?? ''));
$prefillEmail = strtolower(trim((string) ($_GET['email'] ?? '')));
$verified = false;

if ($token !== '') {
  $stmt = db()->prepare(
    'SELECT id, email, email_verification_sent_at, email_verified_at, phone_verified_at
     FROM users
     WHERE email_verification_token = :token AND email_verified_at IS NULL
     LIMIT 1'
  );
  $stmt->execute(['token' => hash_token($token)]);
  $user = $stmt->fetch();

  if ($user) {
    $sentAt = $user['email_verification_sent_at'] ?? '';
    $ttlHours = (int) config('email_token_ttl_hours', 48);
    $expired = false;

    if ($sentAt) {
      $sentTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sentAt);
      if ($sentTime && $sentTime < (new DateTimeImmutable())->modify("-{$ttlHours} hours")) {
        $expired = true;
      }
    }

    if ($expired) {
      $errors[] = 'Overovací link vypršal. Požiadajte o nový.';
    } else {
      $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
      $update = db()->prepare(
        'UPDATE users
         SET email_verified_at = :verified_at, email_verification_token = NULL, updated_at = :updated_at
         WHERE id = :id'
      );
      $update->execute([
        'verified_at' => $now,
        'updated_at' => $now,
        'id' => $user['id'],
      ]);
      $verified = true;
      flash('success', 'Email bol úspešne overený.');
      if (empty($user['phone_verified_at'])) {
        $_SESSION['pending_user_id'] = (int) $user['id'];
        flash('info', 'Zostáva overiť telefónne číslo.');
      }
    }
  } else {
    $errors[] = 'Neplatný alebo už použitý overovací link.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Neplatný bezpečnostný token. Skúste to prosím znova.';
  }

  $prefillEmail = strtolower(trim((string) ($_POST['email'] ?? '')));

  if ($prefillEmail === '' || !filter_var($prefillEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Zadajte platný email.';
  }

  if (!$errors) {
    $stmt = db()->prepare('SELECT id, email_verified_at, email_verification_sent_at FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $prefillEmail]);
    $user = $stmt->fetch();

    if (!$user) {
      $errors[] = 'Používateľ s týmto emailom neexistuje.';
    } elseif (!empty($user['email_verified_at'])) {
      flash('info', 'Email je už overený. Môžete sa prihlásiť.');
    } else {
      if (!can_resend($user['email_verification_sent_at'] ?? null, 5)) {
        $errors[] = 'Nový link môžete poslať najskôr o pár minút.';
      } else {
        $emailToken = generate_token();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $update = db()->prepare(
          'UPDATE users SET email_verification_token = :token, email_verification_sent_at = :sent_at, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
          'token' => hash_token($emailToken),
          'sent_at' => $now,
          'updated_at' => $now,
          'id' => $user['id'],
        ]);

        if (send_email_verification($prefillEmail, $emailToken)) {
          flash('success', 'Overovací email bol znovu odoslaný.');
        } else {
          $errors[] = 'Nepodarilo sa odoslať overovací email.';
        }
      }
    }
  }
}

render_header('Overenie emailu');
?>
  <div class="container">
    <div class="auth-card">
      <h1 class="section-title">Overenie emailu</h1>
      <?php render_flash(); ?>
      <?php if ($errors): ?>
        <div class="alert alert-error">
          <button type="button" class="alert-close" aria-label="Zavrieť">x</button>
          <ul>
            <?php foreach ($errors as $error): ?>
              <li><?= e($error) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($verified): ?>
        <div class="alert alert-success">
          <button type="button" class="alert-close" aria-label="Zavrieť">x</button>
          Email je overený. Pokračujte overením telefónu alebo sa prihláste.
        </div>
        <div class="auth-links">
          <a href="/verify-phone.php">Overiť telefón</a>
          <a href="/login.php">Prihlásiť sa</a>
        </div>
      <?php endif; ?>
      <form method="post" action="/verify-email.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label for="email">Znovu poslať overovací email</label>
          <input class="form-control" type="email" id="email" name="email" value="<?= e($prefillEmail) ?>" placeholder="vas@email.sk">
        </div>
        <button class="btn-secondary" type="submit">Odoslať nový link</button>
      </form>
    </div>
  </div>
<?php
render_footer();
