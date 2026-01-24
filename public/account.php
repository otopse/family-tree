<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';

$user = require_login();

render_header('Môj účet');
?>
  <div class="container">
    <div class="auth-card">
      <h1 class="section-title">Môj účet</h1>
      <?php render_flash(); ?>
      <div class="form-group">
        <label>Email</label>
        <div><?= e($user['email']) ?></div>
      </div>
      <div class="form-group">
        <label>Telefón</label>
        <div><?= e($user['phone']) ?></div>
      </div>
      <div class="form-group">
        <label>Stav overenia</label>
        <div>
          Email: <?= $user['email_verified_at'] ? 'overený' : 'neoverený' ?>,
          Telefón: <?= $user['phone_verified_at'] ? 'overený' : 'neoverený' ?>
        </div>
      </div>
      <div class="auth-links">
        <a href="/logout.php">Odhlásiť sa</a>
      </div>
    </div>
  </div>
<?php
render_footer();
