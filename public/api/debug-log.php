<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$context = $input['context'] ?? '';

if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message required']);
    exit;
}

$debugLog = __DIR__ . '/../gedcom_debug.log';
$logMessage = date('Y-m-d H:i:s') . " [JS] " . $message;
if (!empty($context)) {
    $logMessage .= " | Context: " . $context;
}
$logMessage .= "\n";

file_put_contents($debugLog, $logMessage, FILE_APPEND);

echo json_encode(['success' => true]);
