<?php
/**
 * TRACEGRAD — Alumni Purchased Photo Viewer / Download
 * PHP 7.2 compatible.
 *
 * Security rules:
 * - requires an authenticated Alumni session;
 * - the requested order must belong to that alumnus;
 * - payment must be Verified;
 * - order must be Paid or Completed;
 * - only files stored in assets/gallery/ are served.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/schema-compat.php';
require_once __DIR__ . '/includes/shared/session-security.php';

$currentAlumni = tgRequireCurrentAlumniSession($pdo, 'alum-login.php');

tgRequireDefenseUpgradeSchema($pdo);

$alumniId = (int)$currentAlumni['graduate_id'];
$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;

if ($orderId <= 0) {
    http_response_code(400);
    exit('Invalid gallery order.');
}

$stmt = $pdo->prepare(
    "SELECT
        o.order_id,
        o.graduate_id,
        o.order_status,
        o.amount,
        o.order_date,
        gi.image_id,
        gi.filename,
        gi.original_filename,
        gi.title,
        gi.image_type,
        ga.album_name,
        (
            SELECT p.payment_status
            FROM payments p
            WHERE p.order_id = o.order_id
            ORDER BY p.payment_id DESC
            LIMIT 1
        ) AS payment_status
     FROM gallery_orders o
     JOIN gallery_images gi
       ON gi.image_id = o.image_id
     JOIN gallery_albums ga
       ON ga.album_id = gi.album_id
     JOIN graduates og
       ON og.graduate_id = o.graduate_id
     JOIN courses oc
       ON oc.course_id = og.course_id
     WHERE o.order_id = ?
       AND o.graduate_id = ?
       AND ga.college_id = oc.college_id
     LIMIT 1"
);
$stmt->execute([$orderId, $alumniId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    exit('Purchased photo not found.');
}

$paymentVerified = isset($order['payment_status']) && $order['payment_status'] === 'Verified';
$orderDownloadable = in_array($order['order_status'], ['Paid', 'Completed'], true);

if (!$paymentVerified || !$orderDownloadable) {
    http_response_code(403);
    exit('This photo becomes available after payment verification.');
}

$storedFilename = trim((string)$order['filename']);
if ($storedFilename === '' || basename($storedFilename) !== $storedFilename) {
    http_response_code(404);
    exit('The purchased photo file is unavailable.');
}

$galleryDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'gallery' . DIRECTORY_SEPARATOR;
$filePath = $galleryDir . $storedFilename;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('The purchased photo file is unavailable.');
}

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$mime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($filePath);
}
if ($mime === '' && !empty($order['image_type'])) {
    $mime = trim((string)$order['image_type']);
}

if (!isset($allowedMime[$mime])) {
    http_response_code(415);
    exit('This gallery file type cannot be displayed.');
}

function tg_gallery_download_safe_filename($value, $fallbackExt)
{
    $value = basename(trim((string)$value));
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
    $value = trim((string)$value, '._-');

    if ($value === '') {
        $value = 'TRACEGRAD_Photo.' . $fallbackExt;
    }

    if (strpos($value, '.') === false) {
        $value .= '.' . $fallbackExt;
    }

    return substr($value, 0, 180);
}

function tg_gallery_download_esc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$preferredName = trim((string)($order['original_filename'] ?? ''));
if ($preferredName === '') {
    $preferredName = trim((string)($order['title'] ?? ''));
}
$downloadName = tg_gallery_download_safe_filename(
    $preferredName !== '' ? $preferredName : $storedFilename,
    $allowedMime[$mime]
);

/* Inline image endpoint used by the secure viewer. */
if (isset($_GET['preview'])) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

/* Explicit download endpoint. */
if (isset($_GET['download'])) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

$title = trim((string)($order['title'] ?? ''));
if ($title === '') {
    $title = $preferredName !== '' ? $preferredName : 'Purchased Gallery Photo';
}
$albumName = trim((string)($order['album_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= tg_gallery_download_esc($title) ?> — TRACEGRAD</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#071b30;color:#172033}.viewer{min-height:100vh;display:grid;grid-template-rows:auto 1fr;background:linear-gradient(135deg,#071b30,#102e50)}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px;border-bottom:1px solid rgba(255,255,255,.1);color:#fff}.brand strong{display:block;font-size:18px;letter-spacing:.04em}.brand span{display:block;margin-top:3px;color:#bfd0e1;font-size:12px}.back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid rgba(255,255,255,.18);border-radius:10px;color:#fff;text-decoration:none;background:rgba(255,255,255,.07)}.content{width:min(1180px,calc(100% - 32px));margin:28px auto 40px;display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}.photo-card,.info-card{border:1px solid rgba(255,255,255,.12);border-radius:18px;background:#fff;box-shadow:0 24px 60px rgba(0,0,0,.25);overflow:hidden}.photo-stage{min-height:520px;display:flex;align-items:center;justify-content:center;padding:18px;background:#edf2f7}.photo-stage img{display:block;max-width:100%;max-height:72vh;object-fit:contain;border-radius:10px;box-shadow:0 10px 30px rgba(15,35,55,.14)}.info-card{padding:22px}.verified{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;background:#eaf8ef;color:#257044;font-size:12px;font-weight:700}.info-card h1{margin:14px 0 6px;color:#102a43;font-size:24px;line-height:1.25}.meta{margin:0 0 18px;color:#6b7a8f;font-size:13px;line-height:1.6}.detail{display:grid;gap:10px;margin:18px 0}.detail div{padding:11px;border:1px solid #e5ebf1;border-radius:10px;background:#f8fafc}.detail span,.detail strong{display:block}.detail span{color:#8190a2;font-size:11px;text-transform:uppercase;letter-spacing:.05em}.detail strong{margin-top:3px;color:#33475b;font-size:14px}.download{width:100%;min-height:48px;display:inline-flex;align-items:center;justify-content:center;padding:0 16px;border-radius:11px;background:linear-gradient(135deg,#d6a72c,#e5bb49);color:#0b2240;text-decoration:none;font-weight:800}.note{margin:12px 0 0;color:#78879a;font-size:12px;line-height:1.5}@media(max-width:850px){.content{grid-template-columns:1fr}.photo-stage{min-height:360px}.info-card{order:-1}}@media(max-width:520px){.top{padding:14px 16px}.brand span{display:none}.content{width:min(100% - 20px,1180px);margin-top:16px}.photo-stage{min-height:280px;padding:10px}.info-card{padding:18px}.info-card h1{font-size:20px}}
</style>
</head>
<body>
<div class="viewer">
  <header class="top">
    <div class="brand">
      <strong>TRACEGRAD</strong>
      <span>Purchased Photo Viewer</span>
    </div>
    <a class="back" href="alumni-dashboard.php?tab=orders">← Back to My Orders</a>
  </header>

  <main class="content">
    <section class="photo-card" aria-label="Purchased photo preview">
      <div class="photo-stage">
        <img
          src="gallery-download.php?order=<?= (int)$orderId ?>&amp;preview=1"
          alt="<?= tg_gallery_download_esc($title) ?>"
        >
      </div>
    </section>

    <aside class="info-card">
      <span class="verified">✓ Payment Verified</span>
      <h1><?= tg_gallery_download_esc($title) ?></h1>
      <p class="meta">View your purchased photo first, then use the button below to save the original file to your device.</p>

      <div class="detail">
        <div><span>Order</span><strong>#<?= (int)$order['order_id'] ?></strong></div>
        <?php if ($albumName !== ''): ?>
          <div><span>Album</span><strong><?= tg_gallery_download_esc($albumName) ?></strong></div>
        <?php endif; ?>
        <div><span>Amount</span><strong>₱<?= number_format((float)$order['amount'], 2) ?></strong></div>
      </div>

      <a class="download" href="gallery-download.php?order=<?= (int)$orderId ?>&amp;download=1">Download Original Photo</a>
      <p class="note">Only the alumni account that owns this verified order can view or download this file.</p>
    </aside>
  </main>
</div>
</body>
</html>
