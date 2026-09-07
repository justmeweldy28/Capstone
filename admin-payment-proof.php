<?php
/**
 * TRACEGRAD
 * Secure Super Admin Payment Proof Viewer
 */

define('TRACEGRAD_ROOT', __DIR__);

require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/bootstrap.php';
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/auth.php';
require_once TRACEGRAD_ROOT . '/includes/schema-compat.php';

tgRequireDefenseUpgradeSchema($pdo);

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    exit('Invalid payment proof request.');
}

$stmt = $pdo->prepare(
    "SELECT p.payment_id, p.proof_of_payment
     FROM payments p
     INNER JOIN gallery_orders o ON o.order_id = p.order_id
     WHERE p.order_id = ?
       AND p.proof_of_payment IS NOT NULL
       AND p.proof_of_payment <> ''
     ORDER BY p.payment_id DESC
     LIMIT 1"
);
$stmt->execute(array($orderId));
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    http_response_code(404);
    exit('Payment proof was not found.');
}

$filename = trim((string) $payment['proof_of_payment']);
if ($filename === '' || basename($filename) !== $filename || strpos($filename, '..') !== false) {
    http_response_code(404);
    exit('Payment proof is unavailable.');
}

$filePath = TRACEGRAD_ROOT
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'payments'
    . DIRECTORY_SEPARATOR . $filename;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Payment proof file is missing.');
}

$mime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($filePath);
}

if ($mime === '') {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $fallbackMimeTypes = array(
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf'
    );
    if (isset($fallbackMimeTypes[$extension])) {
        $mime = $fallbackMimeTypes[$extension];
    }
}

$allowedMimeTypes = array(
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf'
);

if (!in_array($mime, $allowedMimeTypes, true)) {
    http_response_code(415);
    exit('Unsupported payment proof format.');
}

$safeFilename = str_replace(array('"', "\r", "\n"), '', basename($filename));

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
