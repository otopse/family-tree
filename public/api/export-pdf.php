<?php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

$user = require_login();
$treeId = (int)($_GET['id'] ?? 0);

if (!$treeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tree ID required']);
    exit;
}

// Verify ownership
$stmt = db()->prepare('SELECT * FROM family_trees WHERE id = :id AND owner = :owner');
$stmt->execute(['id' => $treeId, 'owner' => $user['id']]);
$tree = $stmt->fetch();

if (!$tree) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Get SVG data from POST
$input = json_decode(file_get_contents('php://input'), true);
$svgData = $input['svg'] ?? '';

if (empty($svgData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'SVG data required']);
    exit;
}

// Use jsPDF and svg2pdf.js approach - return SVG data for client-side conversion
// Or use server-side library like TCPDF/dompdf with SVG support

// For now, return SVG data and let client handle conversion
// In production, you might want to use a library like TCPDF or dompdf

echo json_encode([
    'success' => true,
    'svg' => $svgData,
    'filename' => 'rodokmen_' . $treeId . '_' . date('Y-m-d') . '.svg'
]);
