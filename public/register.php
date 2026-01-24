<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$errors = [];
$values = [
  'email' => '',
  'phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Neplatný bezpečnostný token. Skúste to prosím znova.';
  }

  $values['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
  $values['phone'] = normalize_phone((string) ($_POST['phone'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');
  $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

  if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Zadajte platný email.';
  }

  if ($values['phone'] === '' || !preg_match('/^\+?[0-9]{6,15}$/', $values['phone'])) {
    $errors[] = 'Zadajte platné telefónne číslo.';
  }

  if (strlen($password) < 8) {
    $errors[] = 'Heslo musí mať aspoň 8 znakov.';
  }

  if ($password !== $passwordConfirm) {
    $errors[] = 'Heslá sa nezhodujú.';
  }

  if (!$errors) {
    $stmt = db()->prepare('SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1');
    $stmt->execute([
      'email' => $values['email'],
      'phone' => $values['phone'],
    ]);

    if ($stmt->fetch()) {
      $errors[] = 'Používateľ s týmto emailom alebo telefónom už existuje.';
    }
  }

  if (!$errors) {
    $emailToken = generate_token();
    $smsCode = (string) random_int(100000, 999999);
    $phoneCodeToStore = should_store_phone_code_hashed() ? hash_token($smsCode) : $smsCode;
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $stmt = db()->prepare(
      'INSERT INTO users (email, phone, password_hash, email_verification_token, email_verification_sent_at, phone_verification_code, phone_verification_sent_at, created_at, updated_at)
       VALUES (:email, :phone, :password_hash, :email_token, :email_sent_at, :phone_code, :phone_sent_at, :created_at, :updated_at)'
    );

    $stmt->execute([
      'email' => $values['email'],
      'phone' => $values['phone'],
      'password_hash' => password_hash($password, PASSWORD_DEFAULT),
      'email_token' => hash_token($emailToken),
      'email_sent_at' => $now,
      'phone_code' => $phoneCodeToStore,
      'phone_sent_at' => $now,
      'created_at' => $now,
      'updated_at' => $now,
    ]);

    $_SESSION['pending_user_id'] = (int) db()->lastInsertId();

    $emailSent = send_email_verification($values['email'], $emailToken);
    $smsSent = send_sms_code($values['phone'], $smsCode);

    if ($emailSent) {
      flash('success', 'Overovací email bol odoslaný.');
    } else {
      flash('error', 'Nepodarilo sa odoslať overovací email. Skontrolujte nastavenie SMTP/MAIL.');
    }

    if ($smsSent) {
      flash('success', 'SMS kód bol odoslaný.');
    } else {
      flash('error', 'Nepodarilo sa odoslať SMS kód. Skontrolujte SMS nastavenia.');
    }

    redirect('/verify-phone.php?email=' . urlencode($values['email']));
  }
}

render_header('Registrácia');
?>
  <div class="container">
    <div class="auth-card">
      <h1 class="section-title">Registrácia</h1>
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
      <form method="post" action="/register.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label for="email">Email</label>
          <input class="form-control" type="email" id="email" name="email" value="<?= e($values['email']) ?>" required>
        </div>
        <div class="form-group">
          <label for="phone">Telefónne číslo</label>
          <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($values['phone']) ?>" placeholder="+421901234567" required>
          <div class="help-text">Zadajte číslo vrátane predvoľby krajiny.</div>
        </div>
        <div class="form-group">
          <label for="password">Heslo</label>
          <input class="form-control" type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
          <label for="password_confirm">Potvrdenie hesla</label>
          <input class="form-control" type="password" id="password_confirm" name="password_confirm" required>
        </div>
        <button class="btn-primary btn-large" type="submit">Vytvoriť účet</button>
        <div class="auth-links">
          <span>Už máte účet?</span>
          <a href="/login.php">Prihlásiť sa</a>
        </div>
      </form>
    </div>
  </div>
<?php
render_footer();
