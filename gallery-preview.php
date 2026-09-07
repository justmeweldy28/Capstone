<?php
/**
 * TRACEGRAD Public Gallery Preview
 *
 * Serves a resized preview rather than exposing the stored filename
 * in public HTML. Paid / watermarked images receive a visible preview
 * watermark when PHP GD is available.
 *
 * Original-download authorization must remain inside the authenticated
 * alumni gallery/order workflow.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/schema-compat.php';

if (!tgDbColumnExists($pdo, 'gallery_albums', 'college_id')) {
    // The defense-upgrade migration has not been applied yet.
    // Do not expose legacy gallery files without department ownership rules.
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600" viewBox="0 0 900 600"><rect width="900" height="600" fill="#062451"/><text x="450" y="285" fill="#fff" font-family="Arial,sans-serif" font-size="30" text-anchor="middle">TRACEGRAD</text><text x="450" y="330" fill="#d2deea" font-family="Arial,sans-serif" font-size="18" text-anchor="middle">Database upgrade required for gallery preview</text></svg>';
    exit;
}


function tgGalleryPreviewSvg($message)
{
    header(
        'Content-Type: image/svg+xml; charset=UTF-8'
    );

    header('Cache-Control: no-store');

    $safe =
        htmlspecialchars(
            (string) $message,
            ENT_QUOTES,
            'UTF-8'
        );

    echo
        '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="600" viewBox="0 0 900 600">'
        .
        '<rect width="900" height="600" fill="#062451"/>'
        .
        '<circle cx="450" cy="245" r="58" fill="#f0ad16"/>'
        .
        '<path d="M430 245h40M450 225v40" stroke="#fff" stroke-width="8" stroke-linecap="round"/>'
        .
        '<text x="450" y="340" fill="#fff" font-family="Arial,sans-serif" font-size="30" text-anchor="middle">TRACEGRAD</text>'
        .
        '<text x="450" y="382" fill="#d2deea" font-family="Arial,sans-serif" font-size="18" text-anchor="middle">'
        .
        $safe
        .
        '</text>'
        .
        '</svg>';

    exit;
}


$imageId =
    isset($_GET['id'])
        ? (int) $_GET['id']
        : 0;

if ($imageId <= 0) {
    tgGalleryPreviewSvg(
        'Preview unavailable'
    );
}


try {

    $stmt =
        $pdo->prepare(
            "SELECT gi.image_id,
                    gi.filename,
                    gi.title,
                    gi.download_price,
                    gi.watermark_enabled,
                    ga.album_name,
                    ga.college_id
             FROM gallery_images gi
             INNER JOIN gallery_albums ga
               ON ga.album_id = gi.album_id
             WHERE gi.image_id = ?
               AND gi.status='Available'
               AND ga.status='Active'
             LIMIT 1"
        );

    $stmt->execute([$imageId]);

    $item =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        tgGalleryPreviewSvg(
            'Gallery item unavailable'
        );
    }

    /*
     * College-owned albums are private to their own alumni and authorized
     * administrators. Institution-wide albums (college_id IS NULL) remain
     * eligible for the public landing-page preview.
     */
    $albumCollegeId = isset($item['college_id']) ? (int)$item['college_id'] : 0;
    if ($albumCollegeId > 0) {
        $authorized = false;

        $roleId = (int)($_SESSION['role_id'] ?? 0);
        if (!empty($_SESSION['admin_id']) && $roleId === 1) {
            $authorized = true;
        } elseif (!empty($_SESSION['admin_id']) && $roleId === 2) {
            $authorized = (int)($_SESSION['admin_college_id'] ?? 0) === $albumCollegeId;
        } elseif (!empty($_SESSION['alumni_id'])) {
            $scopeStmt = $pdo->prepare(
                "SELECT c.college_id
                 FROM graduates g
                 JOIN courses c ON c.course_id=g.course_id
                 WHERE g.graduate_id=?
                 LIMIT 1"
            );
            $scopeStmt->execute([(int)$_SESSION['alumni_id']]);
            $authorized = (int)$scopeStmt->fetchColumn() === $albumCollegeId;
        }

        if (!$authorized) {
            tgGalleryPreviewSvg('Gallery item unavailable');
        }
    }


    $galleryDirectory =
        realpath(
            __DIR__
            .
            '/assets/gallery'
        );

    if ($galleryDirectory === false) {
        tgGalleryPreviewSvg(
            'Gallery preview unavailable'
        );
    }


    $source =
        realpath(
            $galleryDirectory
            .
            DIRECTORY_SEPARATOR
            .
            basename($item['filename'])
        );

    if (
        $source === false
        ||
        strpos(
            $source,
            $galleryDirectory
            .
            DIRECTORY_SEPARATOR
        ) !== 0
        ||
        !is_file($source)
    ) {
        tgGalleryPreviewSvg(
            'Gallery image unavailable'
        );
    }


    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        $finfo->file($source);

    $supported = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!in_array($mime, $supported, true)) {
        tgGalleryPreviewSvg(
            'Unsupported image preview'
        );
    }


    $paid =
        (float) $item['download_price'] > 0;

    $needsWatermark =
        $paid
        ||
        !empty($item['watermark_enabled']);


    /*
     * If GD is unavailable, do not expose a paid original.
     */
    if (!function_exists('imagecreatetruecolor')) {

        if ($paid) {
            tgGalleryPreviewSvg(
                'Paid preview requires the PHP GD extension'
            );
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline');
        header('Cache-Control: public, max-age=900');

        readfile($source);
        exit;
    }


    if ($mime === 'image/jpeg') {
        $image =
            @imagecreatefromjpeg($source);

    } elseif ($mime === 'image/png') {
        $image =
            @imagecreatefrompng($source);

    } else {
        $image =
            function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($source)
                : false;
    }


    if (!$image) {
        tgGalleryPreviewSvg(
            'Unable to render preview'
        );
    }


    $sourceWidth =
        imagesx($image);

    $sourceHeight =
        imagesy($image);

    $maxWidth = 1280;
    $maxHeight = 900;

    $scale =
        min(
            1,
            $maxWidth / max(1, $sourceWidth),
            $maxHeight / max(1, $sourceHeight)
        );

    $width =
        max(
            1,
            (int) round(
                $sourceWidth * $scale
            )
        );

    $height =
        max(
            1,
            (int) round(
                $sourceHeight * $scale
            )
        );


    $canvas =
        imagecreatetruecolor(
            $width,
            $height
        );

    $white =
        imagecolorallocate(
            $canvas,
            255,
            255,
            255
        );

    imagefill(
        $canvas,
        0,
        0,
        $white
    );


    imagecopyresampled(
        $canvas,
        $image,
        0,
        0,
        0,
        0,
        $width,
        $height,
        $sourceWidth,
        $sourceHeight
    );


    if ($needsWatermark) {

        $overlay =
            imagecolorallocatealpha(
                $canvas,
                3,
                20,
                47,
                65
            );

        imagefilledrectangle(
            $canvas,
            0,
            max(0, $height - 62),
            $width,
            $height,
            $overlay
        );

        $gold =
            imagecolorallocate(
                $canvas,
                243,
                173,
                12
            );

        $whiteText =
            imagecolorallocate(
                $canvas,
                255,
                255,
                255
            );

        imagestring(
            $canvas,
            5,
            18,
            max(5, $height - 45),
            'TRACEGRAD · ISUFST SAN ENRIQUE',
            $whiteText
        );

        if ($paid) {
            imagestring(
                $canvas,
                3,
                18,
                max(5, $height - 23),
                'PUBLIC PREVIEW · ORIGINAL AVAILABLE THROUGH ALUMNI GALLERY',
                $gold
            );
        }
    }


    imagedestroy($image);

    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=900');

    imagejpeg(
        $canvas,
        null,
        84
    );

    imagedestroy($canvas);

} catch (Throwable $e) {

    error_log(
        'TRACEGRAD gallery preview error: '
        .
        $e->getMessage()
    );

    tgGalleryPreviewSvg(
        'Preview temporarily unavailable'
    );
}
