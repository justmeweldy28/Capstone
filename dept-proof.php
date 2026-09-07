<?php
/**
 * TRACEGRAD - Department-scoped proof viewer
 * Serves alumni survey/employment proof only to the Department Admin whose
 * assigned college owns the graduate record.
 */
require_once __DIR__ . '/includes/dept-admin-dashboard/auth.php';

$graduateId = isset($_GET['graduate_id']) ? (int)$_GET['graduate_id'] : 0;
$kind = isset($_GET['kind']) ? strtolower(trim((string)$_GET['kind'])) : '';
if ($graduateId <= 0 || !in_array($kind, array('workplace','company'), true)) {
    http_response_code(400);
    exit('Invalid proof request.');
}

$column = $kind === 'workplace' ? 'workplace_photo' : 'company_id_proof';
$stmt = $pdo->prepare(
    "SELECT g.`" . $column . "` AS proof_file
     FROM graduates g
     JOIN courses c ON c.course_id=g.course_id
     WHERE g.graduate_id=? AND c.college_id=?
     LIMIT 1"
);
$stmt->execute(array($graduateId, (int)$collegeId));
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Proof was not found for this department.');
}

$filename = trim((string)$row['proof_file']);
if ($filename === '' || basename($filename) !== $filename || strpos($filename, '..') !== false) {
    http_response_code(404);
    exit('Proof file is not available.');
}

$path = __DIR__ . '/assets/survey/' . $filename;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Proof file is missing from storage.');
}

$mime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($path);
}
if ($mime === '') {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $fallback = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf');
    $mime = isset($fallback[$ext]) ? $fallback[$ext] : '';
}
$allowed = array('image/jpeg','image/png','image/webp','application/pdf');
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Unsupported proof format.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . str_replace(array('"',"\r","\n"), '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
readfile($path);
exit;
