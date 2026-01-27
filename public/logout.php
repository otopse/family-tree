<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Remove debug log if exists
$debugLog = __DIR__ . '/gedcom_debug.log';
if (file_exists($debugLog)) {
    unlink($debugLog);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

redirect('/');
