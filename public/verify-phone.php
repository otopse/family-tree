<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$errors = [];
$prefillIdentifier = trim((string) ($_GET['email'] ?? ''));
$verified = false;

if ($prefillIdentifier === '' && !empty($_SESSION['pending_user_id'])) {
  $stmt = db()->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
  $stmt->execute(['id' => $_SESSION['pending_user_id']]);
  $user = $stmt->fetch();
  if ($user && !empty($user['email'])) {
    $prefillIdentifier = $user['email'];
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Neplatný bezpečnostný token. Skúste to prosím znova.';
  }

  $action = (string) ($_POST['action'] ?? 'verify');
  $identifier = trim((string) ($_POST['identifier'] ?? $prefillIdentifier));
  $code = trim((string) ($_POST['code'] ?? ''));
  $prefillIdentifier = $identifier;
  $code = preg_replace('/\D+/', '', $code ?? '');

  if ($identifier === '') {
    $errors[] = 'Zadajte email alebo telefónne číslo.';
  }

  if ($action === 'verify' && $code === '') {
    $errors[] = 'Zadajte SMS kód.';
  }

  if (!$errors) {
    $email = strtolower($identifier);
    $phone = normalize_phone($identifier);

    $stmt = db()->prepare(
      'SELECT id, email, phone, phone_verified_at, phone_verification_code, phone_verification_sent_at
       FROM users
       WHERE email = :email OR phone = :phone
       LIMIT 1'
    );
    $stmt->execute([
      'email' => $email,
      'phone' => $phone,
    ]);
    $user = $stmt->fetch();

    if (!$user) {
      $errors[] = 'Používateľ s týmito údajmi neexistuje.';
    } elseif (!empty($user['phone_verified_at'])) {
      flash('info', 'Telefón je už overený. Môžete sa prihlásiť.');
    } else {
      if ($action === 'resend') {
        if (!can_resend($user['phone_verification_sent_at'] ?? null, 2)) {
          $errors[] = 'Nový kód môžete poslať najskôr o pár minút.';
        } else {
          $smsCode = (string) random_int(100000, 999999);
          $phoneCodeToStore = should_store_phone_code_hashed() ? hash_token($smsCode) : $smsCode;
          $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
          $update = db()->prepare(
            'UPDATE users SET phone_verification_code = :code, phone_verification_sent_at = :sent_at, updated_at = :updated_at WHERE id = :id'
          );
          $update->execute([
            'code' => $phoneCodeToStore,
            'sent_at' => $now,
            'updated_at' => $now,
            'id' => $user['id'],
          ]);

          if (send_sms_code($user['phone'], $smsCode)) {
            flash('success', 'SMS kód bol znovu odoslaný.');
          } else {
            $errors[] = 'Nepodarilo sa odoslať SMS kód.';
          }
        }
      } else {
        $sentAt = $user['phone_verification_sent_at'] ?? '';
        $ttlMinutes = (int) config('phone_code_ttl_minutes', 10);
        $expired = false;

        if ($sentAt) {
          $sentTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sentAt);
          if ($sentTime && $sentTime < (new DateTimeImmutable())->modify("-{$ttlMinutes} minutes")) {
            $expired = true;
          }
        }

        if ($expired) {
          $errors[] = 'SMS kód vypršal. Pošlite si nový.';
        } else {
          $storedCode = (string) ($user['phone_verification_code'] ?? '');
          if ($storedCode === '') {
            $errors[] = 'SMS kód už nie je platný. Pošlite si nový.';
          } else {
            $codeHash = hash_token($code);
            $matches = hash_equals($storedCode, $codeHash) || hash_equals($storedCode, $code);
            if (!$matches) {
              $errors[] = 'Nesprávny SMS kód.';
            }
          }
        }
        if (!$errors) {
          $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
          $update = db()->prepare(
            'UPDATE users
             SET phone_verified_at = :verified_at, phone_verification_code = NULL, updated_at = :updated_at
             WHERE id = :id'
          );
          $update->execute([
            'verified_at' => $now,
            'updated_at' => $now,
            'id' => $user['id'],
          ]);
          $verified = true;
          flash('success', 'Telefón bol úspešne overený.');
          unset($_SESSION['pending_user_id']);
        }
      }
    }
  }
}

render_header('Overenie telefónu');
?>
  <div class="container">
    <div class="auth-card">
      <h1 class="section-title">Overenie telefónu</h1>
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
      <?php if ($verified): ?>
        <div class="alert alert-success">Telefón je overený. Môžete sa prihlásiť.</div>
        <div class="auth-links">
          <a href="/login.php">Prihlásiť sa</a>
        </div>
      <?php endif; ?>
      <form method="post" action="/verify-phone.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify">
        <div class="form-group">
          <label for="identifier">Email alebo telefón</label>
          <input class="form-control" type="text" id="identifier" name="identifier" value="<?= e($prefillIdentifier) ?>" placeholder="vas@email.sk alebo +421..." required>
        </div>
        <div class="form-group">
          <label for="code">SMS kód</label>
          <input class="form-control" type="text" id="code" name="code" inputmode="numeric" maxlength="6" required>
        </div>
        <button class="btn-primary btn-large" type="submit">Overiť telefón</button>
      </form>
      <form method="post" action="/verify-phone.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="resend">
        <input type="hidden" name="identifier" value="<?= e($prefillIdentifier) ?>">
        <button class="btn-secondary" type="submit">Znovu poslať SMS kód</button>
      </form>
    </div>
  </div>
<?php
render_footer();
