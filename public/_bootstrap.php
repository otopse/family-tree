<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  ini_set('session.cookie_secure', '1');
}

session_start();
date_default_timezone_set('Europe/Bratislava');

$envFile = dirname(__DIR__) . '/_sub/db.php';
if (file_exists($envFile)) {
  $envValues = require $envFile;
  if (is_array($envValues)) {
    foreach ($envValues as $key => $value) {
      if (!is_string($key) || $key === '' || $value === null) {
        continue;
      }
      $stringValue = (string) $value;
      putenv($key . '=' . $stringValue);
      $_ENV[$key] = $stringValue;
      $_SERVER[$key] = $stringValue;
    }
  }
}

$config = [
  'db_host' => getenv('DB_HOST') ?: 'localhost',
  'db_name' => getenv('DB_NAME') ?: '',
  'db_user' => getenv('DB_USER') ?: '',
  'db_pass' => getenv('DB_PASS') ?: '',
  'db_port' => getenv('DB_PORT') ?: '3306',
  'db_charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
  'app_url' => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
  'mail_from' => getenv('MAIL_FROM') ?: 'no-reply@family-tree.cz',
  'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'Family Tree',
  'sms_api_url' => getenv('SMS_API_URL') ?: '',
  'sms_api_token' => getenv('SMS_API_TOKEN') ?: '',
  'phone_code_ttl_minutes' => (int) (getenv('PHONE_CODE_TTL_MINUTES') ?: 10),
  'email_token_ttl_hours' => (int) (getenv('EMAIL_TOKEN_TTL_HOURS') ?: 48),
];

function config(string $key, $default = null) {
  global $config;
  return $config[$key] ?? $default;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s;port=%s',
    config('db_host'),
    config('db_name'),
    config('db_charset'),
    config('db_port')
  );

  $pdo = new PDO(
    $dsn,
    config('db_user'),
    config('db_pass'),
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );

  return $pdo;
}

function is_https(): bool {
  return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function base_url(): string {
  $configured = (string) config('app_url', '');
  if ($configured !== '') {
    return $configured;
  }

  $host = $_SERVER['HTTP_HOST'] ?? '';
  if ($host === '') {
    return '';
  }

  return (is_https() ? 'https://' : 'http://') . $host;
}

function e(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string) $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
  $sessionToken = $_SESSION['csrf_token'] ?? '';
  if ($token === null || $token === '') {
    return false;
  }
  return hash_equals((string) $sessionToken, $token);
}

function flash(string $type, string $message): void {
  $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array {
  $messages = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $messages;
}

function normalize_phone(string $phone): string {
  $phone = trim($phone);
  $phone = preg_replace('/\s+/', '', $phone);
  $phone = preg_replace('/[^0-9+]/', '', $phone);
  return $phone ?? '';
}

function generate_token(int $bytes = 32): string {
  return bin2hex(random_bytes($bytes));
}

function hash_token(string $token): string {
  return hash('sha256', $token);
}

function should_store_phone_code_hashed(): bool {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }

  try {
    $stmt = db()->query(
      "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'users'
         AND COLUMN_NAME = 'phone_verification_code'
       LIMIT 1"
    );
    $column = $stmt->fetch();
    if (!$column) {
      $cache = true;
      return $cache;
    }

    $type = strtolower((string) ($column['DATA_TYPE'] ?? ''));
    $length = (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'double'];

    if (in_array($type, $numericTypes, true)) {
      $cache = false;
      return $cache;
    }

    if ($length > 0 && $length < 64) {
      $cache = false;
      return $cache;
    }
  } catch (Throwable $e) {
    $cache = true;
    return $cache;
  }

  $cache = true;
  return $cache;
}

function can_resend(?string $sentAt, int $minMinutes): bool {
  if ($sentAt === null || $sentAt === '') {
    return true;
  }
  $sentTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $sentAt);
  if (!$sentTime) {
    return true;
  }
  return $sentTime <= (new DateTimeImmutable())->modify("-{$minMinutes} minutes");
}

function send_email_verification(string $email, string $token): bool {
  $base = base_url();
  if ($base === '') {
    return false;
  }

  $verifyUrl = rtrim($base, '/') . '/verify-email.php?token=' . urlencode($token);
  $subject = 'Overenie emailu';
  $body = "Dobrý deň,\n\n"
    . "Potvrďte svoj email kliknutím na odkaz:\n{$verifyUrl}\n\n"
    . "Ak ste registráciu nerobili, správu ignorujte.\n";

  $from = sprintf('%s <%s>', config('mail_from_name'), config('mail_from'));
  $headers = "From: {$from}\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n";

  return mail($email, $subject, $body, $headers);
}

function send_sms_code(string $phone, string $code): bool {
  $message = "Váš overovací kód pre Family Tree je {$code}.";
  $apiUrl = (string) config('sms_api_url', '');
  $apiToken = (string) config('sms_api_token', '');

  if ($apiUrl !== '') {
    if (!function_exists('curl_init')) {
      return false;
    }

    $payload = json_encode([
      'to' => $phone,
      'message' => $message,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
      return false;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => array_filter([
        'Content-Type: application/json',
        $apiToken !== '' ? 'Authorization: Bearer ' . $apiToken : null,
      ]),
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_TIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $httpCode < 200 || $httpCode >= 300) {
      return false;
    }

    return true;
  }

  error_log("SMS verification for {$phone}: {$code}");
  return true;
}

function current_user(): ?array {
  if (empty($_SESSION['user_id'])) {
    return null;
  }

  $stmt = db()->prepare('SELECT id, email, phone, email_verified_at, phone_verified_at FROM users WHERE id = :id');
  $stmt->execute(['id' => $_SESSION['user_id']]);
  $user = $stmt->fetch();

  return $user ?: null;
}

function require_login(): array {
  $user = current_user();
  if (!$user) {
    flash('info', 'Najprv sa prosím prihláste.');
    redirect('/login.php');
  }
  return $user;
}
