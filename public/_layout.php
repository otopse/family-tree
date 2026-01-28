<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function render_flash(): void {
  $messages = consume_flash();
  foreach ($messages as $message) {
    $type = $message['type'] ?? 'info';
    $text = $message['message'] ?? '';
    echo '<div class="alert alert-' . e($type) . '">';
    echo '<button type="button" class="alert-close" aria-label="Zavrieť">x</button>';
    echo e($text);
    echo '</div>';
  }
}

function render_header(string $title): void {
  $safeTitle = e($title);
  $cssVersion = file_exists(__DIR__ . '/assets/style.css')
    ? filemtime(__DIR__ . '/assets/style.css')
    : time();
  $user = current_user();

  echo '<!doctype html>';
  echo '<html lang="sk">';
  echo '<head>';
  echo '  <meta charset="utf-8">';
  echo '  <meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '  <title>' . $safeTitle . ' - Family Tree</title>';
  echo '  <link rel="stylesheet" href="/assets/style.css?v=' . $cssVersion . '">';
  echo '</head>';
  echo '<body>';
  echo '  <nav class="navbar">';
  echo '    <div class="nav-container">';
  echo '      <div class="nav-brand"><a href="/">Family Tree</a></div>';
  echo '      <ul class="nav-menu">';
  echo '        <li><a href="/#home">Home</a></li>';
  echo '        <li><a href="/#features">Funkcie</a></li>';
  echo '        <li><a href="/#pricing">Cenník</a></li>';
  echo '        <li><a href="/#contact">Kontakt</a></li>';
  echo '      </ul>';
      echo '      <div class="nav-auth">';
      if ($user) {
        $displayName = $user['username'] ?? $user['email'] ?? 'Účet';
        echo '        <div class="nav-user">';
        echo '          <button type="button" class="nav-user-toggle">' . e($displayName) . '</button>';
        echo '          <div class="nav-user-menu">';
        echo '            <a href="/account.php">Môj účet</a>';
        echo '            <a href="/family-trees.php">Moje rodokmene</a>';
        echo '            <a href="/public-trees.php">Public Trees</a>';
        echo '            <a href="/logout.php">Odhlásiť sa</a>';
        echo '          </div>';
        echo '        </div>';
      } else {
        echo '        <a href="/login.php" class="btn-link">Prihlásenie</a>';
        echo '        <a href="/register.php" class="btn-primary">Registrácia</a>';
      }
      echo '      </div>';
  echo '      <button class="nav-toggle" aria-label="Toggle menu">';
  echo '        <span></span><span></span><span></span>';
  echo '      </button>';
  echo '    </div>';
  echo '  </nav>';
  echo '  <main class="auth-page">';
}

function render_footer(): void {
  $jsVersion = file_exists(__DIR__ . '/assets/app.js')
    ? filemtime(__DIR__ . '/assets/app.js')
    : time();

  echo '  </main>';
  echo '  <footer class="footer">';
  echo '    <div class="container">';
  echo '      <p>&copy; 2026 Family Tree. Všetky práva vyhradené.</p>';
  echo '    </div>';
  echo '  </footer>';
  echo '  <script src="/assets/app.js?v=' . $jsVersion . '"></script>';
  echo '</body>';
  echo '</html>';
}
