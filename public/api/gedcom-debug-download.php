<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_layout.php';

// Only for logged-in users (so log is not public)
$user = require_login();

$debugLogPath = __DIR__ . '/../gedcom_debug.log';
if (!is_file($debugLogPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(404);
    echo "Debug log file not found. Open edit-tree first to generate it.";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="gedcom_debug.log"');
header('Cache-Control: no-cache');
readfile($debugLogPath);
