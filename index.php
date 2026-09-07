<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/schema-compat.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* ───────────────────────────────
   PUBLIC LANDING PAGE IMAGE CONFIG
   ------------------------------------------------
   Change only these paths when you want to use a new
   logo, campus photo, developer photo, adviser photo,
   or default college photo.

   College-specific photos are resolved automatically from:
   assets/images/colleges/<college-code-lowercase>.jpg

   Example:
   CICI -> assets/images/colleges/cici.jpg
─────────────────────────────── */

$landingImages = [
    'logo' => 'assets/images/tracegrad-logo.png',

    'home_campus' =>
        'assets/images/landing/home-campus.jpg',

    'about_campus' =>
        'assets/images/landing/about-campus.jpg',

    'college_default' =>
        'assets/images/colleges/default-college.jpg',

    'developers' => [
        'wildie' =>
            'assets/devs/wildie.png',

        'aristotle' =>
            'assets/devs/aristotle.jpg',

        'geraldine' =>
            'assets/devs/geraldine.jpg',

        'justine' =>
            'assets/devs/justine.jpg',

        'adviser' =>
            'assets/devs/panes.jpg'
    ]
];


/**
 * Build the expected public photo path for a college.
 *
 * Example:
 * CICI -> assets/images/colleges/cici.jpg
 *
 * Keep college filenames lowercase and use only letters,
 * numbers, hyphens, and underscores.
 */
function tgCollegePhotoPath($collegeCode)
{
    $collegeCode =
        strtolower(
            trim(
                (string) $collegeCode
            )
        );

    $collegeCode =
        preg_replace(
            '/[^a-z0-9_-]+/',
            '-',
            $collegeCode
        );

    $collegeCode =
        trim(
            $collegeCode,
            '-'
        );

    if ($collegeCode === '') {
        return 'assets/images/colleges/default-college.jpg';
    }

    return
        'assets/images/colleges/'
        .
        $collegeCode
        .
        '.jpg';
}


/**
 * Resolve an optional College / Department logo.
 *
 * Put official logos in:
 * assets/images/colleges/logos/
 *
 * The filename must match the lowercase college code.
 *
 * Supported examples:
 * CICI -> cici.png
 * COT  -> cot.png
 * COE  -> coe.png
 *
 * Supported image formats:
 * PNG, WEBP, JPG, JPEG
 *
 * If no logo exists, an institutional building icon is shown.
 */
function tgCollegeLogoPath($collegeCode)
{
    $collegeCode =
        strtolower(
            trim(
                (string) $collegeCode
            )
        );

    $collegeCode =
        preg_replace(
            '/[^a-z0-9_-]+/',
            '-',
            $collegeCode
        );

    $collegeCode =
        trim(
            $collegeCode,
            '-'
        );

    if ($collegeCode === '') {
        return '';
    }

    $relativeBase =
        'assets/images/colleges/logos/'
        .
        $collegeCode;

    $extensions = [
        'png',
        'webp',
        'jpg',
        'jpeg'
    ];

    foreach ($extensions as $extension) {

        $relativePath =
            $relativeBase
            .
            '.'
            .
            $extension;

        $physicalPath =
            __DIR__
            .
            DIRECTORY_SEPARATOR
            .
            str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativePath
            );

        if (is_file($physicalPath)) {
            return $relativePath;
        }
    }

    return '';
}


/* ───────────────────────────────
   LIVE STATS FROM THE DATABASE
─────────────────────────────── */
$totalAlumni   = (int) $pdo->query("SELECT COUNT(*) FROM graduates")->fetchColumn();
$totalColleges = (int) $pdo->query("SELECT COUNT(*) FROM colleges WHERE status='Active'")->fetchColumn();
$totalCourses  = (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status='Active'")->fetchColumn();

$partnerEmployers = 0;
$employedAlumni   = 0;

try {
    $partnerEmployers = (int) $pdo->query(
        "SELECT COUNT(*) FROM companies"
    )->fetchColumn();
} catch (Throwable $e) {
    $partnerEmployers = 0;
}

$surveyPct = 0;

if ($totalAlumni > 0) {
    $answered = (int) $pdo->query(
        "SELECT COUNT(DISTINCT graduate_id) FROM survey_answers"
    )->fetchColumn();

    $surveyPct = round(($answered / $totalAlumni) * 100);
}

$employedPct = 0;

if ($totalAlumni > 0) {
    $employedAlumni = (int) $pdo->query(
        "SELECT COUNT(DISTINCT graduate_id)
         FROM employment
         WHERE employment_status IN ('Employed','Self-Employed','Freelancer')"
    )->fetchColumn();

    $employedPct = min(
        100,
        (int) round(($employedAlumni / $totalAlumni) * 100)
    );
}


/* Colleges + course counts, for the About section */
$colleges = $pdo->query(
    "SELECT c.college_id,
            c.college_code,
            c.college_name,
            (
                SELECT GROUP_CONCAT(course_code SEPARATOR ', ')
                FROM courses
                WHERE college_id = c.college_id
                  AND status='Active'
            ) AS course_codes,
            (
                SELECT COUNT(*)
                FROM courses
                WHERE college_id = c.college_id
                  AND status='Active'
            ) AS program_count,
            (
                SELECT COUNT(DISTINCT g.graduate_id)
                FROM graduates g
                INNER JOIN courses cr
                    ON cr.course_id = g.course_id
                WHERE cr.college_id = c.college_id
            ) AS alumni_count
     FROM colleges c
     WHERE c.status='Active'
     ORDER BY c.college_name"
)->fetchAll();


/* Active programs grouped by college for the public Colleges page. */
$collegePrograms = [];

$programRows = $pdo->query(
    "SELECT cr.course_id,
            cr.college_id,
            cr.course_code,
            cr.course_name,
            cr.course_major,
            COUNT(DISTINCT g.graduate_id) AS alumni_count
     FROM courses cr
     LEFT JOIN graduates g
       ON g.course_id = cr.course_id
     WHERE cr.status='Active'
     GROUP BY cr.course_id,
              cr.college_id,
              cr.course_code,
              cr.course_name,
              cr.course_major
     ORDER BY cr.course_name"
)->fetchAll();

foreach ($programRows as $programRow) {
    $collegeId = (int) $programRow['college_id'];

    if (!isset($collegePrograms[$collegeId])) {
        $collegePrograms[$collegeId] = [];
    }

    $collegePrograms[$collegeId][] = $programRow;
}


/* Public gallery — active albums / available images */
$gallerySchemaReady = tgDbColumnExists($pdo, 'gallery_albums', 'college_id');
$galleryItems = array();
$galleryAlbums = array();
$galleryPhotoCount = 0;
$galleryAlbumCount = 0;
$galleryFreeCount = 0;

/*
 * Department-owned galleries require gallery_albums.college_id.
 * On an older database, keep the public gallery unavailable instead of
 * throwing a fatal SQL error or accidentally exposing department photos.
 */
if ($gallerySchemaReady) {
    $galleryItems = $pdo->query(
        "SELECT gi.image_id,
                gi.filename,
                gi.original_filename,
                gi.title,
                gi.download_price,
                gi.watermark_enabled,
                gi.uploaded_at,
                ga.album_name,
                ga.event_date
         FROM gallery_images gi
         JOIN gallery_albums ga
           ON ga.album_id = gi.album_id
         WHERE gi.status='Available'
           AND ga.status='Active'
           AND ga.college_id IS NULL
         ORDER BY gi.uploaded_at DESC
         LIMIT 48"
    )->fetchAll();

    $galleryAlbums = $pdo->query(
        "SELECT album_name
         FROM gallery_albums
         WHERE status='Active'
           AND college_id IS NULL
         ORDER BY COALESCE(event_date, '1900-01-01') DESC,
                  album_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $galleryPhotoCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM gallery_images gi
         INNER JOIN gallery_albums ga
           ON ga.album_id = gi.album_id
         WHERE gi.status='Available'
           AND ga.status='Active'
           AND ga.college_id IS NULL"
    )->fetchColumn();

    $galleryAlbumCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM gallery_albums
         WHERE status='Active'
           AND college_id IS NULL"
    )->fetchColumn();

    $galleryFreeCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM gallery_images gi
         INNER JOIN gallery_albums ga
           ON ga.album_id = gi.album_id
         WHERE gi.status='Available'
           AND ga.status='Active'
           AND ga.college_id IS NULL
           AND gi.download_price <= 0"
    )->fetchColumn();
}


/* Latest announcements — published, not yet expired */
$announcements = $pdo->query(
    "SELECT title,
            content,
            publish_date
     FROM announcements
     WHERE status='Published'
       AND (
            expiration_date IS NULL
            OR expiration_date >= NOW()
       )
     ORDER BY publish_date DESC
     LIMIT 3"
)->fetchAll();


function esc($s)
{
    return htmlspecialchars(
        $s ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/* Short preview text for announcement cards */
function previewText($s, $len = 110)
{
    $s = trim(strip_tags($s ?? ''));

    if (mb_strlen($s) <= $len) {
        return $s;
    }

    return mb_substr($s, 0, $len) . '…';
}


/* ───────────────────────────────
   PUBLIC CONTACT PAGE
─────────────────────────────── */

$campusContact = [
    'address' => 'San Enrique, Iloilo, Philippines',
    'email'   => 'sanenriquecampus@gmail.com',
    'phone'   => '(033) 327-3405',
    'website' => 'www.isufst.edu.ph'
];


/* Contact form is shown only after the migration is installed. */
$contactFormReady = false;

try {
    $contactTableCheck = $pdo->query(
        "SHOW TABLES LIKE 'contact_messages'"
    );

    $contactFormReady =
        $contactTableCheck
        &&
        $contactTableCheck->fetchColumn() !== false;

} catch (Throwable $e) {
    $contactFormReady = false;
}


if (empty($_SESSION['tg_contact_csrf'])) {
    try {
        $_SESSION['tg_contact_csrf'] =
            bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['tg_contact_csrf'] =
            hash(
                'sha256',
                session_id() . microtime(true)
            );
    }
}

$contactCsrf =
    (string) $_SESSION['tg_contact_csrf'];


$contactFlash = null;

$contactState =
    isset($_GET['contact'])
        ? trim((string) $_GET['contact'])
        : '';

if ($contactState === 'sent') {
    $contactFlash = [
        'ok',
        'Thank you. Your message has been received by TRACEGRAD.'
    ];

} elseif ($contactState === 'invalid') {
    $contactFlash = [
        'error',
        'Please check the form and complete the required fields.'
    ];

} elseif ($contactState === 'rate') {
    $contactFlash = [
        'error',
        'Please wait a moment before sending another message.'
    ];

} elseif ($contactState === 'setup') {
    $contactFlash = [
        'error',
        'The Contact Messages database migration still needs to be imported.'
    ];

} elseif ($contactState === 'error') {
    $contactFlash = [
        'error',
        'Your message could not be saved. Please try again.'
    ];
}


$developers = [
    [
        'name' => 'Wildie Pescador',
        'role' => 'Lead Developer',
        'detail' =>
            'Full-stack development, database integration, architecture, and overall TRACEGRAD implementation.',
        'image' => $landingImages['developers']['wildie']
    ],
    [
        'name' => 'Aristotle Tamita',
        'role' => 'UI/UX Designer',
        'detail' =>
            'User-interface design, visual organization, usability support, and presentation refinement.',
        'image' => $landingImages['developers']['aristotle']
    ],
    [
        'name' => 'Geraldine Moscardon',
        'role' => 'Development Team Member',
        'detail' =>
            'Project collaboration, documentation support, review, and capstone development activities.',
        'image' => $landingImages['developers']['geraldine']
    ],
    [
        'name' => 'Justine Kyle Madiyanon',
        'role' => 'Development Team Member',
        'detail' =>
            'Project collaboration, system support, documentation, and capstone development activities.',
        'image' => $landingImages['developers']['justine']
    ]
];

$adviser = [
    'name' => 'Wenda D. Panes, EDD',
    'role' => 'Thesis Adviser',
    'detail' =>
        'Research adviser and mentor for the TRACEGRAD capstone project.',
    'image' => $landingImages['developers']['adviser']
];



?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    TRACEGRAD – ISUFST San Enrique Alumni Tracer System
</title>

<meta
    name="description"
    content="TRACEGRAD is the official alumni tracer system of Iloilo State University of Fisheries Science and Technology – San Enrique Campus. Track your career journey, complete the CHED Graduate Tracer Survey, and stay connected with your alma mater."
>

<meta
    name="theme-color"
    content="#0b2240"
>

<meta
    property="og:type"
    content="website"
>

<meta
    property="og:title"
    content="TRACEGRAD – ISUFST San Enrique Alumni Tracer System"
>

<meta
    property="og:description"
    content="The official alumni tracking platform of ISUFST San Enrique Campus — connecting graduates, capturing career outcomes, and supporting CHED-compliant reporting."
>

<meta
    property="og:site_name"
    content="TRACEGRAD"
>


<link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"
>

<link
    rel="stylesheet"
    href="assets/style.css"
>

<link
    rel="stylesheet"
    href="assets/css/public-brand-footer-fix.css"
>

<link rel="stylesheet" href="assets/css/public-final-polish.css">

</head>


<body class="tg-public-page">


<div
    id="pg-land"
    class="pg on"
    style="flex-direction:column;min-height:100vh"
>


<!-- =========================================================
     TOP NAVIGATION BAR
========================================================= -->

<nav
    class="land-topnav"
    id="land-topnav"
>

    <div
        class="lnav-logo"
        role="button"
        tabindex="0"
        aria-label="Go to TRACEGRAD home"
        onclick="showSection('home')"
        onkeydown="if(event.key === 'Enter' || event.key === ' ') { event.preventDefault(); showSection('home'); }"
    >

        <div class="lnav-mark">
            <img
                src="<?= esc($landingImages['logo']) ?>"
                alt="TRACEGRAD Logo"
                class="tracegrad-logo-img"
            >
        </div>

        <div class="lnav-brand">

            <div class="b1">
                TRACEGRAD
            </div>

            <div class="b2">
                ISUFST · San Enrique
            </div>

        </div>

    </div>


    <div class="lnav-links">

        <button
            class="lnav-link on"
            id="lnk-home"
            onclick="showSection('home',this)"
        >
            Home
        </button>

        <button
            class="lnav-link"
            id="lnk-about"
            onclick="showSection('about',this)"
        >
            About
        </button>

        <button
            class="lnav-link"
            id="lnk-colleges"
            onclick="showSection('colleges',this)"
        >
            Colleges
        </button>

        <button
            class="lnav-link"
            id="lnk-gallery"
            onclick="showSection('gallery',this)"
        >
            Gallery
        </button>

        <button
            class="lnav-link"
            id="lnk-contact"
            onclick="showSection('contact',this)"
        >
            Contact
        </button>

    </div>


    <div class="lnav-actions">

        <button
            class="btn-land-admin"
            onclick="location.href='admin-login.php'"
        >
            <i class="ti ti-shield-check"></i>
            Admin Login
        </button>


        <button
            class="btn-land-alumni"
            onclick="location.href='alum-login.php'"
        >
            <i class="ti ti-user-graduate"></i>
            Alumni Login
        </button>

    </div>

</nav>


<!-- =========================================================
     SECTION: HOME — APPROVED CONCEPT DESIGN
========================================================= -->

<div
    id="lsec-home"
    class="lsec on tg-concept-home"
    style="flex-direction:column;flex:1;display:flex"
>

<main class="tg-concept-main">

    <!-- HERO -->
    <section class="tg-concept-hero">

        <div class="tg-concept-pattern"></div>

        <div class="tg-concept-hero-inner">

            <div class="tg-concept-hero-copy tg-concept-reveal">

                <span class="tg-concept-welcome">
                    Welcome to TRACEGRAD
                </span>

                <h1>
                    Connecting Graduates.
                    <em>Building Futures.</em>
                </h1>

                <p>
                    TRACEGRAD is the official alumni tracer system of
                    ISUFST San Enrique Campus that bridges the connection
                    between graduates, institutions, and opportunities.
                </p>

                <div class="tg-concept-hero-actions">

                    <button
                        type="button"
                        class="tg-concept-btn gold"
                        onclick="location.href='alum-login.php'"
                    >
                        <i class="ti ti-clipboard-text"></i>
                        Take the Survey
                    </button>

                    <button
                        type="button"
                        class="tg-concept-btn outline"
                        onclick="showSection('about')"
                    >
                        Learn More
                        <i class="ti ti-chevron-right"></i>
                    </button>

                </div>

                <div class="tg-concept-dots">
                    <span class="on"></span>
                    <span></span>
                    <span></span>
                    <span></span>
                    <span></span>
                </div>

            </div>


            <div class="tg-concept-campus tg-concept-reveal">

                <img
                    src="<?= esc($landingImages['home_campus']) ?>"
                    alt="ISUFST San Enrique Campus"
                    onerror="this.style.display='none';this.parentElement.classList.add('missing');"
                >

                <div class="tg-concept-campus-placeholder">
                    <i class="ti ti-building-bank"></i>
                    <strong>ISUFST San Enrique Campus</strong>
                    <small>
                        Replace the Home photo path in the image config or use
                        assets/images/landing/home-campus.jpg
                    </small>
                </div>

            </div>

        </div>

    </section>


    <!-- KPI STRIP -->
    <section class="tg-concept-kpi-section">

        <div class="tg-concept-container">

            <div class="tg-concept-kpis tg-concept-reveal">

                <article>
                    <i class="ti ti-school navy"></i>
                    <div>
                        <strong data-concept-count="<?= (int)$totalAlumni ?>">
                            <?= number_format($totalAlumni) ?>
                        </strong>
                        <b>Total Alumni</b>
                        <small>All time graduates</small>
                    </div>
                </article>

                <article>
                    <i class="ti ti-briefcase gold"></i>
                    <div>
                        <strong data-concept-count="<?= (int)$employedAlumni ?>">
                            <?= number_format($employedAlumni) ?>
                        </strong>
                        <b>Employed Alumni</b>
                        <small><?= (int)$employedPct ?>% employment rate</small>
                    </div>
                </article>

                <article>
                    <i class="ti ti-building-bank navy"></i>
                    <div>
                        <strong data-concept-count="<?= (int)$partnerEmployers ?>">
                            <?= number_format($partnerEmployers) ?>
                        </strong>
                        <b>Partner Employers</b>
                        <small>Registered companies</small>
                    </div>
                </article>

                <article>
                    <i class="ti ti-chart-line gold"></i>
                    <div>
                        <strong
                            data-concept-count="<?= (int)$surveyPct ?>"
                            data-concept-suffix="%"
                        >
                            <?= (int)$surveyPct ?>%
                        </strong>
                        <b>Response Rate</b>
                        <small>Survey participation</small>
                    </div>
                </article>

            </div>

        </div>

    </section>


    <!-- ANNOUNCEMENTS + GALLERY -->
    <section class="tg-concept-content">

        <div class="tg-concept-container">

            <div class="tg-concept-two-col">

                <article class="tg-concept-panel tg-concept-reveal">

                    <header>
                        <h2>
                            <i class="ti ti-speakerphone"></i>
                            Announcements
                        </h2>

                        <button
                            type="button"
                            onclick="showSection('about')"
                        >
                            View All
                        </button>
                    </header>


                    <div class="tg-concept-announcements">

                        <?php if ($announcements): ?>

                            <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>

                                <div class="tg-concept-announcement">

                                    <time>
                                        <span>
                                            <?= esc(strtoupper(date(
                                                'M',
                                                strtotime($announcement['publish_date'])
                                            ))) ?>
                                        </span>
                                        <strong>
                                            <?= esc(date(
                                                'd',
                                                strtotime($announcement['publish_date'])
                                            )) ?>
                                        </strong>
                                    </time>

                                    <div>
                                        <b><?= esc($announcement['title']) ?></b>
                                        <p>
                                            <?= esc(previewText(
                                                $announcement['content'],
                                                80
                                            )) ?>
                                        </p>
                                    </div>

                                    <i class="ti ti-chevron-right"></i>

                                </div>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <div class="tg-concept-empty">
                                <i class="ti ti-speakerphone-off"></i>
                                <div>
                                    <b>No announcements yet</b>
                                    <span>Published updates will appear here.</span>
                                </div>
                            </div>

                        <?php endif; ?>

                    </div>


                    <button
                        type="button"
                        class="tg-concept-viewmore"
                        onclick="showSection('about')"
                    >
                        See all announcements
                        <i class="ti ti-arrow-right"></i>
                    </button>

                </article>


                <article class="tg-concept-panel tg-concept-reveal">

                    <header>
                        <h2>
                            <i class="ti ti-photo"></i>
                            Gallery Preview
                        </h2>

                        <button
                            type="button"
                            onclick="showSection('gallery')"
                        >
                            View All
                        </button>
                    </header>


                    <?php if ($galleryItems): ?>

                        <div class="tg-concept-gallery">

                            <?php foreach (array_slice($galleryItems, 0, 4) as $item): ?>

                                <button
                                    type="button"
                                    onclick="showSection('gallery')"
                                >
                                    <img
                                        src="gallery-preview.php?id=<?= (int)$item['image_id'] ?>"
                                        alt="<?= esc(
                                            $item['title']
                                            ?: $item['album_name']
                                            ?: 'TRACEGRAD gallery photo'
                                        ) ?>"
                                        loading="lazy"
                                    >
                                </button>

                            <?php endforeach; ?>

                        </div>

                        <div class="tg-concept-gallery-dots">
                            <span class="on"></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>

                    <?php else: ?>

                        <div class="tg-concept-gallery-empty">
                            <i class="ti ti-photo-off"></i>
                            <span>Public gallery preview coming soon.</span>
                        </div>

                    <?php endif; ?>

                </article>

            </div>

        </div>

    </section>


    <!-- FEATURES -->
    <section class="tg-concept-feature-section">

        <div class="tg-concept-container">

            <div class="tg-concept-feature-heading tg-concept-reveal">
                <span></span>
                <h2>Powerful Features for a Smarter Connection</h2>
                <span></span>
            </div>


            <div class="tg-concept-features">

                <article class="tg-concept-reveal">
                    <i class="ti ti-clipboard-text"></i>
                    <div>
                        <b>Survey Management</b>
                        <span>Create, distribute, and manage alumni surveys with ease.</span>
                    </div>
                </article>

                <article class="tg-concept-reveal">
                    <i class="ti ti-users-group"></i>
                    <div>
                        <b>Alumni Tracking</b>
                        <span>Track alumni profiles, employment, and engagement status.</span>
                    </div>
                </article>

                <article class="tg-concept-reveal">
                    <i class="ti ti-map-pin"></i>
                    <div>
                        <b>Workplace Location Map</b>
                        <span>Visualize aggregate alumni workplace distribution.</span>
                    </div>
                </article>

                <article class="tg-concept-reveal">
                    <i class="ti ti-brain"></i>
                    <div>
                        <b>AI Analytics Interpretation</b>
                        <span>AI-assisted insights for smarter decisions.</span>
                    </div>
                </article>

                <article class="tg-concept-reveal">
                    <i class="ti ti-chart-bar"></i>
                    <div>
                        <b>Reports</b>
                        <span>Generate comprehensive reports and dashboards.</span>
                    </div>
                </article>

                <article class="tg-concept-reveal">
                    <i class="ti ti-bell"></i>
                    <div>
                        <b>Notifications</b>
                        <span>Important system notices and alumni reminders.</span>
                    </div>
                </article>

            </div>

        </div>

    </section>


    <!-- TRACEBOT -->
    <section class="tg-concept-bot-section">

        <div class="tg-concept-container">

            <div class="tg-concept-botbar tg-concept-reveal">

                <div class="tg-concept-botidentity">
                    <i class="ti ti-message-chatbot"></i>
                    <div>
                        <b>Hi! I'm TraceBot 👋</b>
                        <span>Your AI Assistant for alumni insights.</span>
                    </div>
                </div>

                <div class="tg-concept-prompts">
                    <button type="button" onclick="toggleChatbot(true)">
                        How do I take the survey?
                    </button>
                    <button type="button" onclick="toggleChatbot(true)">
                        Where can I update my info?
                    </button>
                    <button type="button" onclick="toggleChatbot(true)">
                        I need technical support
                    </button>
                    <button type="button" onclick="toggleChatbot(true)">
                        Ask me anything!
                    </button>
                </div>

                <button
                    type="button"
                    class="tg-concept-chat"
                    onclick="toggleChatbot(true)"
                >
                    Chat with TraceBot
                    <i class="ti ti-send"></i>
                </button>

            </div>

        </div>

    </section>


    <!-- CTA -->
    <section class="tg-concept-cta-section">

        <div class="tg-concept-container">

            <div class="tg-concept-cta tg-concept-reveal">

                <i class="ti ti-users-group"></i>

                <div>
                    <h2>Stay Connected. Stay Inspired.</h2>
                    <p>
                        Your journey continues. Help build a stronger ISUFST community.
                    </p>
                </div>

                <button
                    type="button"
                    class="tg-concept-btn gold"
                    onclick="location.href='alum-login.php'"
                >
                    <i class="ti ti-clipboard-text"></i>
                    Take the Survey Now
                    <i class="ti ti-arrow-right"></i>
                </button>

            </div>

        </div>

    </section>

</main>


<?php
    include __DIR__ . '/includes/public/public-footer.php';
    ?>

</div>


<!-- =========================================================
     SECTION: ABOUT — BIG + EYE-CATCHING DESIGN
========================================================= -->

<div
    id="lsec-about"
    class="lsec tg-about-v2"
    style="flex:1"
>

    <main class="tg-about-v2-main">


        <!-- =====================================================
             ABOUT HERO
        ====================================================== -->

        <section class="tg-about-v2-hero">

            <div class="tg-about-v2-pattern"></div>
            <div class="tg-about-v2-glow"></div>

            <div class="tg-about-v2-container tg-about-v2-hero-grid">

                <div class="tg-about-v2-copy tg-about-v2-reveal">

                    <span class="tg-about-v2-breadcrumb">
                        Home
                        <i class="ti ti-chevron-right"></i>
                        About TRACEGRAD
                    </span>

                    <span class="tg-about-v2-kicker">
                        <i class="ti ti-building-bank"></i>
                        About the System
                    </span>

                    <h1>
                        Alumni tracing for
                        <em>institutional excellence.</em>
                    </h1>

                    <p>
                        TRACEGRAD is a web-based alumni tracer system developed
                        for Iloilo State University of Fisheries Science and
                        Technology – San Enrique Campus. It helps the institution
                        maintain meaningful alumni connections, monitor graduate
                        outcomes, support tracer-study activities, and use
                        aggregate evidence for continuous improvement.
                    </p>

                    <div class="tg-about-v2-actions">

                        <button
                            type="button"
                            class="tg-about-v2-btn gold"
                            onclick="location.href='alum-login.php'"
                        >
                            <i class="ti ti-user-graduate"></i>
                            Alumni Portal
                            <i class="ti ti-arrow-right"></i>
                        </button>

                        <button
                            type="button"
                            class="tg-about-v2-btn outline"
                            onclick="document.getElementById('tg-about-purpose').scrollIntoView({behavior:'smooth'})"
                        >
                            Explore TRACEGRAD
                            <i class="ti ti-arrow-down"></i>
                        </button>

                    </div>

                </div>


                <div class="tg-about-v2-photo tg-about-v2-reveal">

                    <img
                        src="<?= esc($landingImages['about_campus']) ?>"
                        alt="ISUFST San Enrique Campus"
                        onerror="this.style.display='none';this.parentElement.classList.add('missing');"
                    >

                    <div class="tg-about-v2-photo-placeholder">

                        <i class="ti ti-school"></i>

                        <strong>
                            ISUFST San Enrique Campus
                        </strong>

                        <span>
                            Replace the About photo path in the image config or use
                            assets/images/landing/about-campus.jpg.
                        </span>

                    </div>


                    <div class="tg-about-v2-photo-label">

                        <i class="ti ti-map-pin"></i>

                        <span>
                            <strong>
                                ISUFST – San Enrique Campus
                            </strong>

                            <small>
                                San Enrique, Iloilo, Philippines
                            </small>
                        </span>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             ABOUT QUICK STATS
        ====================================================== -->

        <section class="tg-about-v2-stat-section">

            <div class="tg-about-v2-container">

                <div class="tg-about-v2-stats tg-about-v2-reveal">

                    <article>
                        <i class="ti ti-users-group blue"></i>

                        <div>
                            <strong
                                data-about-count="<?= (int)$totalAlumni ?>"
                            >
                                <?= number_format($totalAlumni) ?>
                            </strong>

                            <span>
                                Alumni Records
                            </span>

                            <small>
                                Graduate records in TRACEGRAD
                            </small>
                        </div>
                    </article>


                    <article>
                        <i class="ti ti-building-bank gold"></i>

                        <div>
                            <strong
                                data-about-count="<?= (int)$totalColleges ?>"
                            >
                                <?= number_format($totalColleges) ?>
                            </strong>

                            <span>
                                Active Colleges
                            </span>

                            <small>
                                Academic units represented
                            </small>
                        </div>
                    </article>


                    <article>
                        <i class="ti ti-school green"></i>

                        <div>
                            <strong
                                data-about-count="<?= (int)$totalCourses ?>"
                            >
                                <?= number_format($totalCourses) ?>
                            </strong>

                            <span>
                                Degree Programs
                            </span>

                            <small>
                                Active programs in the system
                            </small>
                        </div>
                    </article>


                    <article>
                        <i class="ti ti-chart-line purple"></i>

                        <div>
                            <strong
                                data-about-count="<?= (int)$surveyPct ?>"
                                data-about-suffix="%"
                            >
                                <?= (int)$surveyPct ?>%
                            </strong>

                            <span>
                                Survey Participation
                            </span>

                            <small>
                                Current public response indicator
                            </small>
                        </div>
                    </article>

                </div>

            </div>

        </section>


        <!-- =====================================================
             PURPOSE + MISSION/VISION
        ====================================================== -->

        <section
            class="tg-about-v2-section tg-about-v2-purpose-section"
            id="tg-about-purpose"
        >

            <div class="tg-about-v2-container">

                <header class="tg-about-v2-heading tg-about-v2-reveal">

                    <div>
                        <span class="tg-about-v2-section-kicker">
                            Why TRACEGRAD Exists
                        </span>

                        <h2>
                            Turning graduate journeys into
                            meaningful institutional insight.
                        </h2>
                    </div>

                    <p>
                        TRACEGRAD gives the campus a structured way to maintain
                        alumni information after graduation, support the
                        institutional tracer survey, follow employment outcomes,
                        communicate with graduates, and prepare aggregate reports.
                    </p>

                </header>


                <div class="tg-about-v2-purpose-grid">

                    <article class="tg-about-v2-purpose-card tg-about-v2-reveal">

                        <span class="tg-about-v2-purpose-icon">
                            <i class="ti ti-target-arrow"></i>
                        </span>

                        <div>
                            <span class="tg-about-v2-card-kicker">
                                System Purpose
                            </span>

                            <h3>
                                Build a reliable alumni evidence base.
                            </h3>

                            <p>
                                TRACEGRAD organizes graduate and career
                                information so authorized users can understand
                                alumni outcomes and improve institutional
                                follow-up.
                            </p>
                        </div>


                        <ul>

                            <li>
                                <i class="ti ti-circle-check-filled"></i>
                                Collect graduate employment and career outcomes
                            </li>

                            <li>
                                <i class="ti ti-circle-check-filled"></i>
                                Support institutional tracer-study participation
                            </li>

                            <li>
                                <i class="ti ti-circle-check-filled"></i>
                                Measure program relevance and graduate outcomes
                            </li>

                            <li>
                                <i class="ti ti-circle-check-filled"></i>
                                Maintain continuing alumni communication
                            </li>

                        </ul>

                    </article>


                    <div class="tg-about-v2-mv-grid">

                        <article class="tg-about-v2-mv-card mission tg-about-v2-reveal">

                            <i class="ti ti-target"></i>

                            <div>
                                <span>
                                    Our Mission
                                </span>

                                <h3>
                                    Connect. Trace. Improve.
                                </h3>

                                <p>
                                    To provide an organized alumni tracing
                                    environment that supports graduate
                                    engagement, meaningful outcome monitoring,
                                    and evidence-based institutional improvement.
                                </p>
                            </div>

                        </article>


                        <article class="tg-about-v2-mv-card vision tg-about-v2-reveal">

                            <i class="ti ti-eye"></i>

                            <div>
                                <span>
                                    Our Vision
                                </span>

                                <h3>
                                    A stronger alumni community powered by data.
                                </h3>

                                <p>
                                    A connected ISUFST alumni community where
                                    graduate outcomes inform academic quality,
                                    career support, institutional planning,
                                    and future opportunities.
                                </p>
                            </div>

                        </article>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             WHAT TRACEGRAD CAN DO
        ====================================================== -->

        <section class="tg-about-v2-section tg-about-v2-capability-section">

            <div class="tg-about-v2-container">

                <div class="tg-about-v2-centered-heading tg-about-v2-reveal">

                    <span class="tg-about-v2-section-kicker">
                        Connected System
                    </span>

                    <h2>
                        What TRACEGRAD helps the institution achieve
                    </h2>

                    <p>
                        The system brings alumni engagement, tracing, follow-up,
                        reporting, and analytics into one coordinated platform.
                    </p>

                </div>


                <div class="tg-about-v2-capability-grid">

                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-users-group"></i>

                        <div>
                            <h3>
                                Alumni Tracking
                            </h3>

                            <p>
                                Organize graduate records, alumni profiles,
                                account status, and continuing alumni engagement.
                            </p>
                        </div>

                        <span>01</span>
                    </article>


                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-clipboard-text"></i>

                        <div>
                            <h3>
                                Tracer Survey
                            </h3>

                            <p>
                                Support structured graduate tracer surveys and
                                preserve historical reporting as survey versions evolve.
                            </p>
                        </div>

                        <span>02</span>
                    </article>


                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-briefcase"></i>

                        <div>
                            <h3>
                                Employment Monitoring
                            </h3>

                            <p>
                                Track career status, industry, job relevance,
                                workplace information, and employment outcomes.
                            </p>
                        </div>

                        <span>03</span>
                    </article>


                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-bell"></i>

                        <div>
                            <h3>
                                Alumni Communication
                            </h3>

                            <p>
                                Send notifications, reminders, verification
                                follow-ups, announcements, and alumni updates.
                            </p>
                        </div>

                        <span>04</span>
                    </article>


                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-file-analytics"></i>

                        <div>
                            <h3>
                                Reports & Analytics
                            </h3>

                            <p>
                                Provide authorized aggregate statistics and
                                structured outputs for institutional reporting.
                            </p>
                        </div>

                        <span>05</span>
                    </article>


                    <article class="tg-about-v2-reveal">
                        <i class="ti ti-brain"></i>

                        <div>
                            <h3>
                                AI-Assisted Insight
                            </h3>

                            <p>
                                Interpret aggregate statistics without sending
                                alumni names, IDs, passwords, or private records
                                to the public assistant.
                            </p>
                        </div>

                        <span>06</span>
                    </article>

                </div>

            </div>

        </section>


        <!-- =====================================================
             COLLEGES REPRESENTED
        ====================================================== -->

        <section class="tg-about-v2-section tg-about-v2-college-section">

            <div class="tg-about-v2-container">

                <header class="tg-about-v2-heading tg-about-v2-reveal">

                    <div>
                        <span class="tg-about-v2-section-kicker">
                            Academic Community
                        </span>

                        <h2>
                            Colleges represented in TRACEGRAD
                        </h2>
                    </div>

                    <p>
                        These cards are generated directly from the active
                        college records currently stored in the TRACEGRAD database.
                    </p>

                </header>


                <?php if ($colleges): ?>

                    <div class="tg-about-v2-college-grid">

                        <?php foreach ($colleges as $college): ?>

                            <article class="tg-about-v2-college-card tg-about-v2-reveal">

                                <?php
                                $aboutCollegeLogo =
                                    tgCollegeLogoPath(
                                        $college['college_code']
                                    );
                                ?>

                                <div class="tg-department-logo tg-department-logo-about">

                                    <?php if ($aboutCollegeLogo !== ''): ?>

                                        <img
                                            src="<?= esc($aboutCollegeLogo) ?>"
                                            alt="<?= esc($college['college_name']) ?> logo"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <span class="tg-department-logo-fallback">
                                            <i class="ti ti-building-bank"></i>
                                        </span>

                                    <?php endif; ?>

                                </div>


                                <div class="tg-about-v2-college-copy">

                                    <span>
                                        <?= esc($college['college_code']) ?>
                                    </span>

                                    <h3>
                                        <?= esc($college['college_name']) ?>
                                    </h3>

                                    <p>
                                        <?= esc(
                                            $college['course_codes']
                                            ?: 'No active programs listed yet'
                                        ) ?>
                                    </p>


                                    <div class="tg-about-v2-college-meta">

                                        <span>
                                            <i class="ti ti-school"></i>
                                            <?= number_format((int)$college['program_count']) ?>
                                            program<?= (int)$college['program_count'] === 1 ? '' : 's' ?>
                                        </span>

                                        <span>
                                            <i class="ti ti-users"></i>
                                            <?= number_format((int)$college['alumni_count']) ?>
                                            alumni
                                        </span>

                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="tg-about-v2-empty">

                        <i class="ti ti-building-off"></i>

                        <strong>
                            No active colleges are available yet.
                        </strong>

                        <span>
                            Active college records will appear here automatically.
                        </span>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- =====================================================
             ALUMNI JOURNEY / WORKFLOW
        ====================================================== -->

        <section class="tg-about-v2-section tg-about-v2-journey-section">

            <div class="tg-about-v2-container">

                <div class="tg-about-v2-centered-heading tg-about-v2-reveal">

                    <span class="tg-about-v2-section-kicker is-light">
                        Alumni Journey
                    </span>

                    <h2>
                        How TRACEGRAD supports the graduate journey
                    </h2>

                    <p>
                        Alumni interaction is designed to stay simple,
                        purposeful, and connected to institutional outcomes.
                    </p>

                </div>


                <div class="tg-about-v2-journey-grid">

                    <article class="tg-about-v2-reveal">

                        <span>01</span>

                        <i class="ti ti-id"></i>

                        <h3>
                            Access your official alumni record
                        </h3>

                        <p>
                            Activate or sign in to your alumni account using
                            the graduate record available in TRACEGRAD.
                        </p>

                    </article>


                    <article class="tg-about-v2-reveal">

                        <span>02</span>

                        <i class="ti ti-user-edit"></i>

                        <h3>
                            Keep supported information current
                        </h3>

                        <p>
                            Maintain contact details, career information,
                            employment history, and supported profile data.
                        </p>

                    </article>


                    <article class="tg-about-v2-reveal">

                        <span>03</span>

                        <i class="ti ti-clipboard-check"></i>

                        <h3>
                            Participate in the tracer survey
                        </h3>

                        <p>
                            Share graduate outcomes that help the institution
                            understand program relevance and career pathways.
                        </p>

                    </article>


                    <article class="tg-about-v2-reveal">

                        <span>04</span>

                        <i class="ti ti-chart-dots-3"></i>

                        <h3>
                            Contribute to institutional improvement
                        </h3>

                        <p>
                            Aggregate alumni information supports planning,
                            reporting, follow-up, and evidence-based decisions.
                        </p>

                    </article>

                </div>

            </div>

        </section>


        <!-- =====================================================
             PRIVACY / DATA USE
        ====================================================== -->

        <section class="tg-about-v2-section tg-about-v2-privacy-section">

            <div class="tg-about-v2-container">

                <div class="tg-about-v2-privacy-card tg-about-v2-reveal">

                    <div class="tg-about-v2-privacy-icon">
                        <i class="ti ti-shield-lock"></i>
                    </div>

                    <div>

                        <span class="tg-about-v2-section-kicker">
                            Privacy-Aware Design
                        </span>

                        <h2>
                            Public information stays aggregate.
                            Protected records stay protected.
                        </h2>

                        <p>
                            TRACEGRAD's public landing page is intended for
                            system information, aggregate statistics, public
                            announcements, and public gallery previews.
                            Individual alumni records and authorized dashboard
                            information remain behind authenticated access.
                        </p>

                    </div>


                    <div class="tg-about-v2-privacy-points">

                        <span>
                            <i class="ti ti-user-off"></i>
                            No public alumni profiles
                        </span>

                        <span>
                            <i class="ti ti-lock"></i>
                            Role-based dashboard access
                        </span>

                        <span>
                            <i class="ti ti-map-pin"></i>
                            No continuous location tracking
                        </span>

                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             ABOUT CTA
        ====================================================== -->

        <section class="tg-about-v2-cta-section">

            <div class="tg-about-v2-container">

                <div class="tg-about-v2-cta tg-about-v2-reveal">

                    <div class="tg-about-v2-cta-icon">
                        <i class="ti ti-users-group"></i>
                    </div>

                    <div>
                        <span>
                            Stay Connected with ISUFST
                        </span>

                        <h2>
                            Your story continues after graduation.
                        </h2>

                        <p>
                            Keep your alumni record current, participate in the
                            tracer survey, and stay connected with your campus.
                        </p>
                    </div>


                    <button
                        type="button"
                        class="tg-about-v2-btn gold"
                        onclick="location.href='alum-login.php'"
                    >
                        <i class="ti ti-user-graduate"></i>
                        Open Alumni Portal
                        <i class="ti ti-arrow-right"></i>
                    </button>

                </div>

            </div>

        </section>

    </main>


    <?php
    include __DIR__ . '/includes/public/public-footer.php';
    ?>

</div>


<!-- =========================================================
     SECTION: COLLEGES — BIG + EYE-CATCHING DESIGN
========================================================= -->

<div
    id="lsec-colleges"
    class="lsec tg-colleges-v2"
    style="flex:1"
>

<main class="tg-colleges-v2-main">

    <section class="tg-colleges-v2-hero">

        <div class="tg-colleges-v2-pattern"></div>

        <div class="tg-colleges-v2-container tg-colleges-v2-hero-grid">

            <div class="tg-colleges-v2-copy tg-colleges-v2-reveal">

                <span class="tg-colleges-v2-breadcrumb">
                    Home
                    <i class="ti ti-chevron-right"></i>
                    Colleges
                </span>

                <span class="tg-colleges-v2-kicker">
                    <i class="ti ti-building-bank"></i>
                    Academic Community
                </span>

                <h1>
                    Colleges that shape
                    <em>future-ready graduates.</em>
                </h1>

                <p>
                    Explore the active academic colleges represented in
                    TRACEGRAD and the degree programs connected to their
                    alumni records.
                </p>

                <div class="tg-colleges-v2-actions">

                    <button
                        type="button"
                        class="tg-colleges-v2-btn gold"
                        onclick="document.getElementById('tg-college-directory').scrollIntoView({behavior:'smooth'})"
                    >
                        <i class="ti ti-building-community"></i>
                        Explore Colleges
                        <i class="ti ti-arrow-down"></i>
                    </button>

                    <button
                        type="button"
                        class="tg-colleges-v2-btn outline"
                        onclick="location.href='alum-login.php'"
                    >
                        <i class="ti ti-user-graduate"></i>
                        Alumni Portal
                    </button>

                </div>

            </div>


            <div class="tg-colleges-v2-hero-stats tg-colleges-v2-reveal">

                <article>
                    <i class="ti ti-building-bank blue"></i>
                    <strong data-college-count="<?= (int)$totalColleges ?>">
                        <?= number_format($totalColleges) ?>
                    </strong>
                    <span>Active Colleges</span>
                </article>

                <article>
                    <i class="ti ti-school gold"></i>
                    <strong data-college-count="<?= (int)$totalCourses ?>">
                        <?= number_format($totalCourses) ?>
                    </strong>
                    <span>Degree Programs</span>
                </article>

                <article>
                    <i class="ti ti-users-group green"></i>
                    <strong data-college-count="<?= (int)$totalAlumni ?>">
                        <?= number_format($totalAlumni) ?>
                    </strong>
                    <span>Alumni Records</span>
                </article>

                <article>
                    <i class="ti ti-chart-line purple"></i>
                    <strong
                        data-college-count="<?= (int)$surveyPct ?>"
                        data-college-suffix="%"
                    >
                        <?= (int)$surveyPct ?>%
                    </strong>
                    <span>Survey Participation</span>
                </article>

            </div>

        </div>

    </section>


    <section
        class="tg-colleges-v2-section"
        id="tg-college-directory"
    >

        <div class="tg-colleges-v2-container">

            <header class="tg-colleges-v2-heading tg-colleges-v2-reveal">

                <div>
                    <span class="tg-colleges-v2-section-kicker">
                        College Directory
                    </span>

                    <h2>
                        Academic colleges represented in TRACEGRAD
                    </h2>
                </div>

                <p>
                    Every college below comes from the active college records
                    in your TRACEGRAD database. Program and alumni counts are
                    calculated from the current course and graduate records.
                </p>

            </header>


            <?php if ($colleges): ?>

                <div class="tg-colleges-v2-grid">

                    <?php foreach ($colleges as $college): ?>

                        <?php
                        $collegeId = (int) $college['college_id'];
                        $programList =
                            isset($collegePrograms[$collegeId])
                                ? $collegePrograms[$collegeId]
                                : [];
                        ?>

                        <article class="tg-colleges-v2-card tg-colleges-v2-reveal">

                            <?php
                            $collegePhoto =
                                tgCollegePhotoPath(
                                    $college['college_code']
                                );
                            ?>

                            <div class="tg-colleges-v2-card-photo">

                                <img
                                    src="<?= esc($collegePhoto) ?>"
                                    alt="<?= esc($college['college_name']) ?>"
                                    loading="lazy"
                                    onerror="
                                        this.onerror=null;
                                        this.src='<?= esc($landingImages['college_default']) ?>';
                                        this.parentElement.classList.add('fallback');
                                    "
                                >

                                <div class="tg-colleges-v2-card-photo-overlay">

                                    <span>
                                        <?= esc($college['college_code']) ?>
                                    </span>

                                    <strong>
                                        <?= esc($college['college_name']) ?>
                                    </strong>

                                </div>

                            </div>

                            <div class="tg-colleges-v2-card-content">

                            <div class="tg-colleges-v2-card-top">

                                <?php
                                $collegeLogo =
                                    tgCollegeLogoPath(
                                        $college['college_code']
                                    );
                                ?>

                                <div class="tg-department-logo tg-department-logo-directory">

                                    <?php if ($collegeLogo !== ''): ?>

                                        <img
                                            src="<?= esc($collegeLogo) ?>"
                                            alt="<?= esc($college['college_name']) ?> logo"
                                            loading="lazy"
                                        >

                                    <?php else: ?>

                                        <span class="tg-department-logo-fallback">
                                            <i class="ti ti-building-bank"></i>
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <span class="tg-colleges-v2-code">
                                    <?= esc($college['college_code']) ?>
                                </span>

                            </div>


                            <h3>
                                <?= esc($college['college_name']) ?>
                            </h3>


                            <p class="tg-colleges-v2-summary">
                                <?= number_format((int)$college['program_count']) ?>
                                active program<?= (int)$college['program_count'] === 1 ? '' : 's' ?>
                                and
                                <?= number_format((int)$college['alumni_count']) ?>
                                graduate record<?= (int)$college['alumni_count'] === 1 ? '' : 's' ?>
                                represented in TRACEGRAD.
                            </p>


                            <div class="tg-colleges-v2-meta">

                                <span>
                                    <i class="ti ti-school"></i>
                                    <?= number_format((int)$college['program_count']) ?>
                                    Programs
                                </span>

                                <span>
                                    <i class="ti ti-users"></i>
                                    <?= number_format((int)$college['alumni_count']) ?>
                                    Alumni
                                </span>

                            </div>


                            <details class="tg-colleges-v2-programs">

                                <summary>
                                    <span>
                                        <i class="ti ti-list-details"></i>
                                        View Degree Programs
                                    </span>

                                    <i class="ti ti-chevron-down"></i>
                                </summary>


                                <div>

                                    <?php if ($programList): ?>

                                        <?php foreach ($programList as $program): ?>

                                            <article>

                                                <span>
                                                    <?= esc($program['course_code']) ?>
                                                </span>

                                                <div>
                                                    <strong>
                                                        <?= esc($program['course_name']) ?>
                                                    </strong>

                                                    <?php if (!empty($program['course_major'])): ?>
                                                        <small>
                                                            Major:
                                                            <?= esc($program['course_major']) ?>
                                                        </small>
                                                    <?php endif; ?>

                                                    <em>
                                                        <?= number_format((int)$program['alumni_count']) ?>
                                                        alumni
                                                    </em>
                                                </div>

                                            </article>

                                        <?php endforeach; ?>

                                    <?php else: ?>

                                        <div class="tg-colleges-v2-no-program">
                                            No active degree program is listed yet.
                                        </div>

                                    <?php endif; ?>

                                </div>

                            </details>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="tg-colleges-v2-empty">

                    <i class="ti ti-building-off"></i>

                    <strong>
                        No active colleges are currently available.
                    </strong>

                    <span>
                        Active college records will appear here automatically.
                    </span>

                </div>

            <?php endif; ?>


            <div class="tg-colleges-v2-values tg-colleges-v2-reveal">

                <div>
                    <span class="tg-colleges-v2-section-kicker is-light">
                        United in Purpose
                    </span>

                    <h2>
                        One campus. Multiple disciplines.
                        One alumni community.
                    </h2>

                    <p>
                        TRACEGRAD helps connect graduate outcomes across
                        colleges so ISUFST can see both college-level and
                        institution-wide trends.
                    </p>
                </div>


                <div class="tg-colleges-v2-values-grid">

                    <article>
                        <i class="ti ti-award"></i>
                        <strong>Quality Education</strong>
                        <span>Graduate evidence supports continuing academic improvement.</span>
                    </article>

                    <article>
                        <i class="ti ti-briefcase"></i>
                        <strong>Career Readiness</strong>
                        <span>Employment outcomes reveal where graduates build their careers.</span>
                    </article>

                    <article>
                        <i class="ti ti-heart-handshake"></i>
                        <strong>Alumni Connection</strong>
                        <span>Engagement continues beyond graduation.</span>
                    </article>

                    <article>
                        <i class="ti ti-chart-dots"></i>
                        <strong>Data-Informed Growth</strong>
                        <span>Aggregate insights support institutional planning and review.</span>
                    </article>

                </div>

            </div>

        </div>

    </section>

</main>


<?php
    include __DIR__ . '/includes/public/public-footer.php';
    ?>

</div>


<!-- =========================================================
     SECTION: GALLERY — BIG + EYE-CATCHING DESIGN
========================================================= -->

<div
    id="lsec-gallery"
    class="lsec tg-gallery-v2"
    style="flex:1"
>

<main class="tg-gallery-v2-main">

    <section class="tg-gallery-v2-hero">

        <div class="tg-gallery-v2-pattern"></div>

        <div class="tg-gallery-v2-container tg-gallery-v2-hero-grid">

            <div class="tg-gallery-v2-copy tg-gallery-v2-reveal">

                <span class="tg-gallery-v2-breadcrumb">
                    Home
                    <i class="ti ti-chevron-right"></i>
                    Gallery
                </span>

                <span class="tg-gallery-v2-kicker">
                    <i class="ti ti-photo"></i>
                    Campus Memories
                </span>

                <h1>
                    Moments worth
                    <em>remembering.</em>
                </h1>

                <p>
                    Explore a public preview of campus events, celebrations,
                    achievements, and alumni memories. Full supported gallery
                    actions remain available through authenticated alumni access.
                </p>

                <div class="tg-gallery-v2-values">
                    <span><i class="ti ti-camera"></i> Captured Moments</span>
                    <span><i class="ti ti-users-group"></i> Alumni Connection</span>
                    <span><i class="ti ti-trophy"></i> Campus Excellence</span>
                </div>

            </div>


            <div class="tg-gallery-v2-stats tg-gallery-v2-reveal">

                <article>
                    <i class="ti ti-photo blue"></i>
                    <strong data-gallery-count="<?= (int)$galleryPhotoCount ?>">
                        <?= number_format($galleryPhotoCount) ?>
                    </strong>
                    <span>Public Items</span>
                </article>

                <article>
                    <i class="ti ti-folders gold"></i>
                    <strong data-gallery-count="<?= (int)$galleryAlbumCount ?>">
                        <?= number_format($galleryAlbumCount) ?>
                    </strong>
                    <span>Active Albums</span>
                </article>

                <article>
                    <i class="ti ti-gift green"></i>
                    <strong data-gallery-count="<?= (int)$galleryFreeCount ?>">
                        <?= number_format($galleryFreeCount) ?>
                    </strong>
                    <span>Free Items</span>
                </article>

                <article>
                    <i class="ti ti-users purple"></i>
                    <strong data-gallery-count="<?= (int)$totalAlumni ?>">
                        <?= number_format($totalAlumni) ?>
                    </strong>
                    <span>Alumni Records</span>
                </article>

            </div>

        </div>

    </section>


    <section class="tg-gallery-v2-section">

        <div class="tg-gallery-v2-container">

            <div class="tg-gallery-v2-toolbar tg-gallery-v2-reveal">

                <div class="tg-gallery-v2-chips">

                    <button
                        type="button"
                        class="on"
                        data-gallery-filter=""
                    >
                        All Photos
                    </button>

                    <?php foreach (array_slice($galleryAlbums, 0, 10) as $albumName): ?>

                        <button
                            type="button"
                            data-gallery-filter="<?= esc(strtolower($albumName)) ?>"
                        >
                            <?= esc($albumName) ?>
                        </button>

                    <?php endforeach; ?>

                </div>


                <div class="tg-gallery-v2-tools">

                    <label class="tg-gallery-v2-search">
                        <i class="ti ti-search"></i>

                        <input
                            type="search"
                            data-gallery-search
                            placeholder="Search gallery..."
                        >
                    </label>


                    <select data-gallery-sort>
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="free">Free First</option>
                    </select>

                </div>

            </div>


            <?php if ($galleryItems): ?>

                <div
                    class="tg-gallery-v2-grid"
                    data-gallery-grid
                >

                    <?php foreach ($galleryItems as $item): ?>

                        <?php
                        $isFree =
                            ((float)$item['download_price']) <= 0;

                        $eventTime =
                            !empty($item['event_date'])
                                ? strtotime($item['event_date'])
                                : strtotime($item['uploaded_at']);

                        $gallerySearch =
                            strtolower(
                                trim(
                                    ($item['title'] ?: '')
                                    . ' '
                                    . ($item['album_name'] ?: '')
                                    . ' '
                                    . ($item['original_filename'] ?: '')
                                )
                            );
                        ?>

                        <article
                            class="tg-gallery-v2-card tg-gallery-v2-reveal"
                            data-gallery-card
                            data-album="<?= esc(strtolower($item['album_name'])) ?>"
                            data-gallery-searchtext="<?= esc($gallerySearch) ?>"
                            data-time="<?= (int)$eventTime ?>"
                            data-free="<?= $isFree ? '1' : '0' ?>"
                        >

                            <div class="tg-gallery-v2-media">

                                <img
                                    src="gallery-preview.php?id=<?= (int)$item['image_id'] ?>"
                                    alt="<?= esc(
                                        $item['title']
                                        ?: $item['album_name']
                                        ?: 'TRACEGRAD gallery image'
                                    ) ?>"
                                    loading="lazy"
                                >

                                <?php if ($isFree): ?>

                                    <span class="tg-gallery-v2-badge free">
                                        Free Preview
                                    </span>

                                <?php else: ?>

                                    <span class="tg-gallery-v2-badge paid">
                                        ₱<?= number_format(
                                            (float)$item['download_price'],
                                            0
                                        ) ?>
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div class="tg-gallery-v2-card-body">

                                <span class="tg-gallery-v2-album">
                                    <?= esc($item['album_name']) ?>
                                </span>

                                <h3>
                                    <?= esc(
                                        $item['title']
                                        ?: $item['original_filename']
                                        ?: 'Campus Memory'
                                    ) ?>
                                </h3>

                                <p>
                                    <i class="ti ti-calendar-event"></i>
                                    <?= esc(date('F j, Y', $eventTime)) ?>
                                </p>


                                <?php if ($isFree): ?>

                                    <button
                                        type="button"
                                        onclick="window.open('gallery-preview.php?id=<?= (int)$item['image_id'] ?>','_blank','noopener')"
                                    >
                                        View Preview
                                        <i class="ti ti-eye"></i>
                                    </button>

                                <?php else: ?>

                                    <button
                                        type="button"
                                        onclick="location.href='alum-login.php'"
                                    >
                                        Sign In to Purchase
                                        <i class="ti ti-lock"></i>
                                    </button>

                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <div
                    class="tg-gallery-v2-empty"
                    data-gallery-empty
                    hidden
                >
                    <i class="ti ti-photo-search"></i>
                    <strong>No gallery item matches your search.</strong>
                    <span>Try another album or keyword.</span>
                </div>


                <div class="tg-gallery-v2-more-row">

                    <button
                        type="button"
                        class="tg-gallery-v2-more"
                        data-gallery-more
                    >
                        Load More Photos
                        <i class="ti ti-refresh"></i>
                    </button>

                </div>

            <?php else: ?>

                <div class="tg-gallery-v2-empty">

                    <i class="ti ti-photo-off"></i>

                    <strong>
                        No public gallery items are available yet.
                    </strong>

                    <span>
                        New active gallery items will appear here automatically.
                    </span>

                </div>

            <?php endif; ?>


            <div class="tg-gallery-v2-cta tg-gallery-v2-reveal">

                <i class="ti ti-photo-heart"></i>

                <div>
                    <span>
                        Alumni Gallery Access
                    </span>

                    <h2>
                        Looking for full gallery features?
                    </h2>

                    <p>
                        Sign in to use supported alumni cart, order, and
                        authenticated gallery actions.
                    </p>
                </div>

                <button
                    type="button"
                    class="tg-gallery-v2-btn"
                    onclick="location.href='alum-login.php'"
                >
                    <i class="ti ti-user-graduate"></i>
                    Alumni Login
                    <i class="ti ti-arrow-right"></i>
                </button>

            </div>

        </div>

    </section>

</main>


<?php
    include __DIR__ . '/includes/public/public-footer.php';
    ?>

</div>


<!-- =========================================================
     SECTION: CONTACT + DEVELOPERS — BIG + EYE-CATCHING DESIGN
========================================================= -->

<div
    id="lsec-contact"
    class="lsec tg-contact-v2"
    style="flex:1"
>

<main class="tg-contact-v2-main">

    <section class="tg-contact-v2-hero">

        <div class="tg-contact-v2-pattern"></div>

        <div class="tg-contact-v2-container tg-contact-v2-hero-grid">

            <div class="tg-contact-v2-copy tg-contact-v2-reveal">

                <span class="tg-contact-v2-breadcrumb">
                    Home
                    <i class="ti ti-chevron-right"></i>
                    Contact
                </span>

                <span class="tg-contact-v2-kicker">
                    <i class="ti ti-headset"></i>
                    Get in Touch
                </span>

                <h1>
                    We're here to
                    <em>help you.</em>
                </h1>

                <p>
                    Reach out for public TRACEGRAD inquiries, alumni-system
                    guidance, technical concerns, or feedback about your
                    experience with the platform.
                </p>

            </div>


            <div class="tg-contact-v2-quick tg-contact-v2-reveal">

                <article>
                    <i class="ti ti-mail blue"></i>

                    <div>
                        <strong>Email Us</strong>
                        <a href="mailto:<?= esc($campusContact['email']) ?>">
                            <?= esc($campusContact['email']) ?>
                        </a>
                    </div>
                </article>

                <article>
                    <i class="ti ti-phone gold"></i>

                    <div>
                        <strong>Call Us</strong>
                        <a href="tel:+63333273405">
                            <?= esc($campusContact['phone']) ?>
                        </a>
                    </div>
                </article>

                <article>
                    <i class="ti ti-map-pin green"></i>

                    <div>
                        <strong>Visit Us</strong>
                        <span>
                            ISUFST – San Enrique Campus
                        </span>
                    </div>
                </article>

            </div>

        </div>

    </section>


    <section class="tg-contact-v2-section">

        <div class="tg-contact-v2-container">

            <?php if ($contactFlash): ?>

                <div
                    class="tg-contact-v2-flash <?= $contactFlash[0] === 'ok' ? 'ok' : 'error' ?>"
                >
                    <i class="ti <?= $contactFlash[0] === 'ok' ? 'ti-circle-check' : 'ti-alert-circle' ?>"></i>
                    <?= esc($contactFlash[1]) ?>
                </div>

            <?php endif; ?>


            <div class="tg-contact-v2-layout">

                <aside class="tg-contact-v2-info tg-contact-v2-reveal">

                    <span class="tg-contact-v2-section-kicker">
                        Contact Information
                    </span>

                    <h2>
                        ISUFST – San Enrique Campus
                    </h2>

                    <p class="tg-contact-v2-info-intro">
                        For campus-level inquiries, use the official contact
                        details below or send a message through the TRACEGRAD
                        contact form.
                    </p>


                    <div class="tg-contact-v2-info-list">

                        <article>
                            <i class="ti ti-map-pin"></i>
                            <div>
                                <strong>Campus Address</strong>
                                <span><?= esc($campusContact['address']) ?></span>
                            </div>
                        </article>

                        <article>
                            <i class="ti ti-mail"></i>
                            <div>
                                <strong>Email Address</strong>
                                <a href="mailto:<?= esc($campusContact['email']) ?>">
                                    <?= esc($campusContact['email']) ?>
                                </a>
                            </div>
                        </article>

                        <article>
                            <i class="ti ti-phone"></i>
                            <div>
                                <strong>Contact Number</strong>
                                <a href="tel:+63333273405">
                                    <?= esc($campusContact['phone']) ?>
                                </a>
                            </div>
                        </article>

                        <article>
                            <i class="ti ti-world"></i>
                            <div>
                                <strong>ISUFST Website</strong>
                                <a
                                    href="https://www.isufst.edu.ph"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <?= esc($campusContact['website']) ?>
                                </a>
                            </div>
                        </article>

                    </div>


                    <div class="tg-contact-v2-ai">

                        <i class="ti ti-message-chatbot"></i>

                        <div>
                            <strong>
                                Need quick TRACEGRAD guidance?
                            </strong>

                            <p>
                                The public AI Assistant can explain public
                                navigation and system features.
                            </p>

                            <button
                                type="button"
                                onclick="toggleChatbot(true)"
                            >
                                Open AI Assistant
                                <i class="ti ti-arrow-right"></i>
                            </button>
                        </div>

                    </div>

                </aside>


                <section class="tg-contact-v2-form-panel tg-contact-v2-reveal">

                    <div class="tg-contact-v2-form-head">

                        <div>
                            <span class="tg-contact-v2-section-kicker">
                                Send Us a Message
                            </span>

                            <h2>
                                How can we help?
                            </h2>
                        </div>

                        <i class="ti ti-send"></i>

                    </div>


                    <?php if ($contactFormReady): ?>

                        <form
                            class="tg-contact-v2-form"
                            method="post"
                            action="contact-submit.php"
                        >

                            <input
                                type="hidden"
                                name="_contact_token"
                                value="<?= esc($contactCsrf) ?>"
                            >

                            <div
                                class="tg-contact-v2-honeypot"
                                aria-hidden="true"
                            >
                                <label>
                                    Website
                                    <input
                                        type="text"
                                        name="website"
                                        tabindex="-1"
                                        autocomplete="off"
                                    >
                                </label>
                            </div>


                            <div class="tg-contact-v2-form-grid">

                                <label>
                                    <span>Full Name</span>

                                    <div>
                                        <i class="ti ti-user"></i>

                                        <input
                                            type="text"
                                            name="fullname"
                                            maxlength="150"
                                            required
                                            placeholder="Your full name"
                                        >
                                    </div>
                                </label>


                                <label>
                                    <span>Email Address</span>

                                    <div>
                                        <i class="ti ti-mail"></i>

                                        <input
                                            type="email"
                                            name="email"
                                            maxlength="190"
                                            required
                                            placeholder="you@example.com"
                                        >
                                    </div>
                                </label>

                            </div>


                            <label>
                                <span>Subject</span>

                                <div>
                                    <i class="ti ti-tag"></i>

                                    <input
                                        type="text"
                                        name="subject"
                                        maxlength="180"
                                        required
                                        placeholder="How can TRACEGRAD help?"
                                    >
                                </div>
                            </label>


                            <label>
                                <span>Your Message</span>

                                <div class="textarea">
                                    <i class="ti ti-message"></i>

                                    <textarea
                                        name="message"
                                        rows="7"
                                        maxlength="3000"
                                        required
                                        placeholder="Write your message here..."
                                    ></textarea>
                                </div>
                            </label>


                            <button
                                type="submit"
                                class="tg-contact-v2-submit"
                            >
                                Send Message
                                <i class="ti ti-send"></i>
                            </button>


                            <p class="tg-contact-v2-privacy">
                                <i class="ti ti-shield-lock"></i>
                                Please do not send passwords, API keys, or
                                private alumni records through this public form.
                            </p>

                        </form>

                    <?php else: ?>

                        <div class="tg-contact-v2-setup">

                            <i class="ti ti-database-cog"></i>

                            <strong>
                                Contact form migration required
                            </strong>

                            <p>
                                Import
                                <code>database/migrations/2026-08-26-public-contact-messages.sql</code>
                                to activate message submission.
                            </p>

                            <a href="mailto:<?= esc($campusContact['email']) ?>">
                                Email the Campus Instead
                            </a>

                        </div>

                    <?php endif; ?>

                </section>

            </div>


            <header class="tg-contact-v2-team-heading tg-contact-v2-reveal">

                <span class="tg-contact-v2-section-kicker">
                    Meet the Minds Behind TRACEGRAD
                </span>

                <h2>
                    Development Team
                </h2>

                <p>
                    TRACEGRAD was developed as an ISUFST capstone project
                    by students of the College of Informatics and Computing
                    Innovations with research guidance from their adviser.
                </p>

            </header>


            <div class="tg-contact-v2-team-grid">

                <?php foreach ($developers as $developer): ?>

                    <?php
                    $parts = preg_split(
                        '/\s+/',
                        trim($developer['name'])
                    );

                    $initials = '';

                    foreach ($parts as $part) {
                        if ($part !== '') {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }

                        if (strlen($initials) >= 2) {
                            break;
                        }
                    }
                    ?>

                    <article class="tg-contact-v2-person tg-contact-v2-reveal">

                        <div class="tg-contact-v2-person-photo">

                            <img
                                src="<?= esc($developer['image']) ?>"
                                alt="<?= esc($developer['name']) ?>"
                                loading="lazy"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                            >

                            <span style="display:none">
                                <?= esc($initials) ?>
                            </span>

                        </div>


                        <div>

                            <h3>
                                <?= esc($developer['name']) ?>
                            </h3>

                            <span class="tg-contact-v2-role">
                                <?= esc($developer['role']) ?>
                            </span>

                            <p>
                                <?= esc($developer['detail']) ?>
                            </p>

                            <small>
                                BS Information Technology · CICI
                            </small>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>


            <article class="tg-contact-v2-adviser tg-contact-v2-reveal">

                <div class="tg-contact-v2-person-photo adviser">

                    <img
                        src="<?= esc($adviser['image']) ?>"
                        alt="<?= esc($adviser['name']) ?>"
                        loading="lazy"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                    >

                    <span style="display:none">
                        WP
                    </span>

                </div>


                <div>

                    <span class="tg-contact-v2-section-kicker">
                        Research Guidance
                    </span>

                    <h3>
                        <?= esc($adviser['name']) ?>
                    </h3>

                    <strong>
                        <?= esc($adviser['role']) ?>
                    </strong>

                    <p>
                        <?= esc($adviser['detail']) ?>
                    </p>

                </div>


                <i class="ti ti-school"></i>

            </article>

        </div>

    </section>

</main>


<?php
    include __DIR__ . '/includes/public/public-footer.php';
    ?>

</div>

<!-- =========================================================
     GLOBAL LANDING-PAGE FLOATING CONTROLS
     These controls intentionally live OUTSIDE the individual
     Home / About / Colleges / Gallery / Contact sections so
     they remain visible while the landing page switches tabs.
========================================================= -->

<!-- =========================================================
     BACK TO TOP
========================================================= -->

<button
    id="back-to-top"
    onclick="window.scrollTo({
        top:0,
        left:0,
        behavior:'smooth'
    })"
    aria-label="Back to top"
>

    <i class="ti ti-arrow-up"></i>

</button>


<!-- =========================================================
     CHATBOT ASSISTANT
========================================================= -->

<button
    id="chatbot-fab"
    onclick="toggleChatbot()"
    aria-label="Open TRACEGRAD AI Assistant"
    aria-controls="chatbot-box"
    aria-expanded="false"
>

    <i class="ti ti-message-chatbot"></i>

    <span class="fab-badge" aria-hidden="true">
        AI
    </span>

</button>


<div
    id="chatbot-box"
    role="dialog"
    aria-label="TRACEGRAD Assistant chat"
>


    <div class="chat-head">

        <div class="chat-head-av">
            <i class="ti ti-fish"></i>
        </div>


        <div class="chat-head-info">

            <div class="ch-name">
                TRACEGRAD Assistant
            </div>

            <div class="ch-status">
                Online · AI-powered public guide
            </div>

        </div>


        <button
            class="chat-head-close"
            onclick="toggleChatbot(false)"
            aria-label="Close chat"
        >

            <i class="ti ti-x"></i>

        </button>

    </div>


    <div
        class="chat-msgs"
        id="chat-msgs"
    ></div>


    <div
        class="chat-quick-btns"
        id="chat-quick-btns"
    >

        <button
            class="chat-qbtn"
            onclick="sendQuick('How do I sign in?')"
        >
            Sign in help
        </button>


        <button
            class="chat-qbtn"
            onclick="sendQuick('I forgot my password')"
        >
            Forgot password
        </button>


        <button
            class="chat-qbtn"
            onclick="sendQuick('How much are the gallery photos?')"
        >
            Gallery pricing
        </button>


        <button
            class="chat-qbtn"
            onclick="sendQuick('Tell me about the CHED survey')"
        >
            CHED survey
        </button>

    </div>


    <div class="chat-input-row">

        <input
            type="text"
            id="chat-input"
            placeholder="Type your question…"
            autocomplete="off"
            onkeydown="if(event.key==='Enter') sendChat();"
        >


        <button
            class="chat-send-btn"
            onclick="sendChat()"
            aria-label="Send message"
        >

            <i class="ti ti-send"></i>

        </button>

    </div>


    <div class="chat-privacy-note">

        <i class="ti ti-shield-lock"></i>

        Private by design: this conversation is never stored —
        not in your browser, not on our server.

    </div>

</div>

</div><!-- /#pg-land -->


<!-- =========================================================
     PHP → JAVASCRIPT LIVE STATISTICS BRIDGE
========================================================= -->

<script>
const TRACEGRAD_STATS = {
    totalAlumni: <?= (int) $totalAlumni ?>,
    totalColleges: <?= (int) $totalColleges ?>,
    totalCourses: <?= (int) $totalCourses ?>,
    surveyPct: <?= (int) $surveyPct ?>,
    employedPct: <?= (int) $employedPct ?>
};
</script>

<script>
/* ============================================================
   TRACEGRAD LANDING PAGE — FIXED HEADER SCROLL BEHAVIOR
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
    const landPage = document.getElementById('pg-land');
    const header = document.getElementById('land-topnav');

    if (!landPage || !header) return;

    function updateLandingHeader() {
        const isScrolled = window.scrollY > 20;

        header.classList.toggle('scrolled', isScrolled);

        /*
         * The mobile header wraps into multiple rows.
         * Measure it so content never hides underneath it.
         */
        const navHeight = header.offsetHeight;

        landPage.style.setProperty(
            '--land-nav-height',
            navHeight + 'px'
        );

        if (window.innerWidth <= 780) {
            landPage.style.paddingTop = navHeight + 'px';
        } else {
            landPage.style.paddingTop = '68px';
        }
    }

    updateLandingHeader();

    window.addEventListener('scroll', updateLandingHeader, {
        passive: true
    });

    window.addEventListener('resize', updateLandingHeader);

    window.addEventListener('load', updateLandingHeader);
});
</script>

<script src="assets/script.js"></script>


</body>
</html>