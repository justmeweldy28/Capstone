<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD — Super Admin Institutional Report Export
 * ------------------------------------------------------------
 * PHP 7.2+
 *
 * Supported templates:
 * - department_summary
 * - employment
 * - roster
 *
 * Supported formats:
 * - html  : browser preview
 * - pdf   : portrait print-ready HTML + browser print dialog
 * - docx  : official Microsoft Word document download
 * - csv   : CSV download
 *
 * Browser preview / Print-PDF reuse the SAME institutional
 * header/footer artwork as the Department Admin reports.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';


/* ============================================================
   AUTHORIZATION
============================================================ */

$currentAdmin = tgRequireCurrentAdminSession($pdo, 'admin-login.php');
if ((int)$currentAdmin['role_id'] !== 1) {
    header('Location: dept-admin-dashboard.php');
    exit;
}


/* ============================================================
   HELPERS
============================================================ */

function saReportEsc($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function saCsvSafe($value)
{
    $text =
        (string)($value ?? '');

    if (
        $text !== ''
        &&
        in_array(
            $text[0],
            ['=', '+', '-', '@'],
            true
        )
    ) {
        return "'" . $text;
    }

    return $text;
}


function saSafeFilename($value)
{
    $value =
        preg_replace(
            '/[^A-Za-z0-9_\-]+/',
            '_',
            (string)$value
        );

    $value =
        trim(
            $value,
            '_'
        );

    return
        $value !== ''
            ? $value
            : 'TRACEGRAD_Report';
}


function saDataUri($mime, $bytes)
{
    if (
        $bytes === false
        ||
        $bytes === null
        ||
        $bytes === ''
    ) {
        return '';
    }

    return
        'data:' .
        $mime .
        ';base64,' .
        base64_encode($bytes);
}


function saOfficialArtwork()
{
    $result = [
        'left' => '',
        'right' => '',
        'footer' => '',
        'header_flat' => '',
        'footer_flat' => '',
        'ready' => false,
        'template' => '',
    ];

    /*
     * Production-safe path: the exact artwork extracted from the
     * supplied Word template is shipped as normal image files.
     * This avoids requiring PHP ZipArchive on XAMPP just to render
     * an official report.
     */
    $leftPath =
        __DIR__ . '/assets/templates/official-isufst-logo.jpeg';

    $rightPath =
        __DIR__ . '/assets/templates/official-bagong-pilipinas.png';

    $footerPath =
        __DIR__ . '/assets/templates/official-report-footer.jpeg';

    $headerFlatPath =
        __DIR__ . '/assets/templates/official-report-header-flat.png';

    $footerFlatPath =
        __DIR__ . '/assets/templates/official-report-footer-flat.png';

    if (
        is_file($leftPath)
        && is_file($rightPath)
        && is_file($footerPath)
    ) {
        $result['left'] = saDataUri(
            'image/jpeg',
            file_get_contents($leftPath)
        );

        $result['right'] = saDataUri(
            'image/png',
            file_get_contents($rightPath)
        );

        $result['footer'] = saDataUri(
            'image/jpeg',
            file_get_contents($footerPath)
        );

        if (is_file($headerFlatPath)) {
            $result['header_flat'] = saDataUri(
                'image/png',
                file_get_contents($headerFlatPath)
            );
        }

        if (is_file($footerFlatPath)) {
            $result['footer_flat'] = saDataUri(
                'image/png',
                file_get_contents($footerFlatPath)
            );
        }

        $result['ready'] =
            $result['left'] !== ''
            && $result['right'] !== ''
            && $result['footer'] !== '';

        $result['template'] =
            'super-admin-report-template.docx';

        if ($result['ready']) {
            return $result;
        }
    }

    /*
     * Optional compatibility fallback: if direct artwork files are
     * missing but ZipArchive is available, read the artwork from the
     * official DOCX or the older survey report template.
     */
    if (!class_exists('ZipArchive')) {
        return $result;
    }

    $templateCandidates = [
        __DIR__ . '/assets/templates/super-admin-report-template.docx',
        __DIR__ . '/assets/templates/survey-report-template.docx',
    ];

    $templatePath = '';

    foreach ($templateCandidates as $candidate) {
        if (is_file($candidate)) {
            $templatePath = $candidate;
            break;
        }
    }

    if ($templatePath === '') {
        return $result;
    }

    $zip = new ZipArchive();
    $opened = $zip->open($templatePath);

    if ($opened !== true) {
        return $result;
    }

    $result['left'] = saDataUri(
        'image/jpeg',
        $zip->getFromName('word/media/image2.jpeg')
    );

    $result['right'] = saDataUri(
        'image/png',
        $zip->getFromName('word/media/image1.png')
    );

    $result['footer'] = saDataUri(
        'image/jpeg',
        $zip->getFromName('word/media/image3.jpeg')
    );

    $zip->close();

    $result['ready'] =
        $result['left'] !== ''
        && $result['right'] !== ''
        && $result['footer'] !== '';

    $result['template'] = basename($templatePath);

    return $result;
}

function saSurveyStatus($answerCount, $questionCount)
{
    $answerCount =
        (int)$answerCount;

    $questionCount =
        (int)$questionCount;

    if ($answerCount <= 0) {
        return 'Not Started';
    }

    if (
        $questionCount > 0
        &&
        $answerCount >= $questionCount
    ) {
        return 'Completed';
    }

    return 'Partial';
}


function saRate($numerator, $denominator)
{
    $numerator =
        (int)$numerator;

    $denominator =
        (int)$denominator;

    if ($denominator <= 0) {
        return 0;
    }

    return
        (int)round(
            ($numerator / $denominator) * 100
        );
}



/* ============================================================
   MICROSOFT WORD / DOCX HELPERS
   ------------------------------------------------------------
   DOCX export starts from the supplied official Word template,
   so the real Word header and footer remain attached to every page.
============================================================ */

function saWordXml($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_XML1,
        'UTF-8'
    );
}

function saWordClean($value)
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)($value ?? ''));
    return preg_replace('/[^\P{C}\n\t]/u', '', $value);
}

function saWordRun($text, $bold = false, $size = 16, $color = '000000')
{
    $parts = explode("\n", saWordClean($text));
    $xml = '';

    foreach ($parts as $index => $part) {
        if ($index > 0) {
            $xml .= '<w:r><w:br/></w:r>';
        }

        $xml .= '<w:r><w:rPr>' .
            '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/>' .
            '<w:sz w:val="' . (int)$size . '"/>' .
            '<w:szCs w:val="' . (int)$size . '"/>' .
            ($bold ? '<w:b/>' : '') .
            '<w:color w:val="' . saWordXml($color) . '"/>' .
            '</w:rPr><w:t xml:space="preserve">' .
            saWordXml($part) .
            '</w:t></w:r>';
    }

    return $xml;
}

function saWordP($text = '', $bold = false, $size = 16, $color = '000000', $align = '', $before = 0, $after = 60, $keepNext = false)
{
    $pPr = '<w:pPr>';

    if ($align !== '') {
        $pPr .= '<w:jc w:val="' . saWordXml($align) . '"/>';
    }

    $pPr .= '<w:spacing w:before="' . (int)$before . '" w:after="' . (int)$after . '"/>';

    if ($keepNext) {
        $pPr .= '<w:keepNext/>';
    }

    $pPr .= '</w:pPr>';

    return '<w:p>' . $pPr . saWordRun($text, $bold, $size, $color) . '</w:p>';
}

function saWordSectionTitle($text)
{
    return '<w:p><w:pPr>' .
        '<w:keepNext/>' .
        '<w:spacing w:before="130" w:after="55"/>' .
        '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="3" w:color="5B9BD5"/></w:pBdr>' .
        '</w:pPr>' .
        saWordRun($text, true, 18, '17365D') .
        '</w:p>';
}

function saWordCell($content, $width, $shade = '', $align = 'left', $border = 'C9D5E2')
{
    $pr = '<w:tcPr>' .
        '<w:tcW w:w="' . (int)$width . '" w:type="dxa"/>' .
        '<w:vAlign w:val="center"/>';

    if ($shade !== '') {
        $pr .= '<w:shd w:fill="' . saWordXml($shade) . '"/>';
    }

    if ($border !== '') {
        $pr .= '<w:tcBorders>' .
            '<w:top w:val="single" w:sz="4" w:color="' . saWordXml($border) . '"/>' .
            '<w:left w:val="single" w:sz="4" w:color="' . saWordXml($border) . '"/>' .
            '<w:bottom w:val="single" w:sz="4" w:color="' . saWordXml($border) . '"/>' .
            '<w:right w:val="single" w:sz="4" w:color="' . saWordXml($border) . '"/>' .
            '</w:tcBorders>';
    }

    $pr .= '<w:tcMar>' .
        '<w:top w:w="55" w:type="dxa"/>' .
        '<w:left w:w="70" w:type="dxa"/>' .
        '<w:bottom w:w="55" w:type="dxa"/>' .
        '<w:right w:w="70" w:type="dxa"/>' .
        '</w:tcMar>' .
        '</w:tcPr>';

    if ($align !== '') {
        $content = preg_replace(
            '/<w:pPr>/',
            '<w:pPr><w:jc w:val="' . saWordXml($align) . '"/>',
            $content,
            1
        );
    }

    return '<w:tc>' . $pr . $content . '</w:tc>';
}

function saWordRow($cells, $header = false, $shade = '')
{
    $rowPr = '<w:trPr>' .
        ($header ? '<w:tblHeader/>' : '') .
        '<w:cantSplit/>' .
        '</w:trPr>';

    return '<w:tr>' . $rowPr . implode('', $cells) . '</w:tr>';
}

function saWordTable($rows, $widths)
{
    $grid = '';
    foreach ($widths as $width) {
        $grid .= '<w:gridCol w:w="' . (int)$width . '"/>';
    }

    return '<w:tbl>' .
        '<w:tblPr>' .
            '<w:tblW w:w="0" w:type="auto"/>' .
            '<w:tblLayout w:type="fixed"/>' .
        '</w:tblPr>' .
        '<w:tblGrid>' . $grid . '</w:tblGrid>' .
        implode('', $rows) .
        '</w:tbl>';
}

function saWordStatusColor($value)
{
    $value = strtolower(trim((string)$value));

    if (in_array($value, ['completed', 'employed', 'self-employed', 'freelancer', 'active'], true)) {
        return '176B3A';
    }

    if (in_array($value, ['partial', 'underemployed', 'pending'], true)) {
        return '8A6400';
    }

    if (in_array($value, ['not started', 'unemployed', 'no employment record', 'inactive'], true)) {
        return 'A13030';
    }

    return '1F2937';
}

function saWordNormalizeWidths($weights, $target = 8300)
{
    $sum = array_sum($weights);
    if ($sum <= 0) {
        return $weights;
    }

    $result = [];
    $running = 0;
    $last = count($weights) - 1;

    foreach ($weights as $index => $weight) {
        if ($index === $last) {
            $width = max(300, $target - $running);
        } else {
            $width = max(300, (int)round(($weight / $sum) * $target));
        }
        $result[] = $width;
        $running += $width;
    }

    return $result;
}

function saWordDetailedWidths($template, $columns)
{
    if ($template === 'department_summary') {
        return saWordNormalizeWidths([420,650,1700,650,650,760,760,780,780,850,650]);
    }

    if ($template === 'employment') {
        /* Fixed portrait widths tuned to keep short headings readable. */
        return [340,650,1100,650,600,650,730,1000,750,700,1130];
    }

    return saWordNormalizeWidths([420,900,1650,1200,820,560,900,560,1150]);
}

function saWordMetaTable($items)
{
    $widths = [1300, 2850, 1300, 2850];
    $rows = [];

    for ($i = 0; $i < count($items); $i += 2) {
        $left = $items[$i];
        $right = isset($items[$i + 1]) ? $items[$i + 1] : ['', ''];

        $rows[] = saWordRow([
            saWordCell(saWordP($left[0], true, 13, '44546A', '', 0, 0), $widths[0], 'F2F6FA'),
            saWordCell(saWordP($left[1], true, 13, '1F2937', '', 0, 0), $widths[1]),
            saWordCell(saWordP($right[0], true, 13, '44546A', '', 0, 0), $widths[2], 'F2F6FA'),
            saWordCell(saWordP($right[1], true, 13, '1F2937', '', 0, 0), $widths[3]),
        ]);
    }

    return saWordTable($rows, $widths);
}

function saWordSummaryTable($summaryRows)
{
    $widths = [3200, 1400, 3700];
    $rows = [];

    $rows[] = saWordRow([
        saWordCell(saWordP('Indicator', true, 13, 'FFFFFF', '', 0, 0), $widths[0], '17365D'),
        saWordCell(saWordP('Count / Value', true, 13, 'FFFFFF', 'center', 0, 0), $widths[1], '17365D', 'center'),
        saWordCell(saWordP('Rate / Interpretation', true, 13, 'FFFFFF', '', 0, 0), $widths[2], '17365D'),
    ], true);

    foreach ($summaryRows as $index => $row) {
        $shade = ($index % 2 === 1) ? 'F8FAFC' : '';
        $rows[] = saWordRow([
            saWordCell(saWordP($row[0], false, 13, '1F2937', '', 0, 0), $widths[0], $shade),
            saWordCell(saWordP($row[1], true, 13, '17365D', 'center', 0, 0), $widths[1], $shade, 'center'),
            saWordCell(saWordP($row[2], false, 13, '536579', '', 0, 0), $widths[2], $shade),
        ]);
    }

    return saWordTable($rows, $widths);
}

function saCreateOfficialDocx($templatePath, $outputPath, $bodyXml)
{
    if (!is_file($templatePath)) {
        throw new RuntimeException('The official Word report template could not be found.');
    }

    if (!copy($templatePath, $outputPath)) {
        throw new RuntimeException('TRACEGRAD could not copy the official Word report template.');
    }

    $documentXml = false;
    $writeDocumentXml = null;
    $closeArchive = null;

    /*
     * Preferred path: ZipArchive when the PHP ZIP extension is enabled.
     */
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($outputPath) !== true) {
            @unlink($outputPath);
            throw new RuntimeException('TRACEGRAD could not open the copied Word template.');
        }

        $documentXml = $zip->getFromName('word/document.xml');

        $writeDocumentXml = function ($xml) use ($zip) {
            return $zip->addFromString('word/document.xml', $xml);
        };

        $closeArchive = function () use ($zip) {
            $zip->close();
        };

    /*
     * XAMPP-friendly fallback: PharData can read/write ZIP containers
     * even when ext-zip / ZipArchive is not enabled. DOCX is a ZIP
     * package, so this keeps Word export functional on more installs.
     */
    } elseif (class_exists('PharData')) {
        try {
            $phar = new PharData($outputPath, 0, null, Phar::ZIP);

            if (!isset($phar['word/document.xml'])) {
                unset($phar);
                @unlink($outputPath);
                throw new RuntimeException('The Word template is missing word/document.xml.');
            }

            $documentXml = $phar['word/document.xml']->getContent();

            $writeDocumentXml = function ($xml) use ($phar) {
                $phar['word/document.xml'] = $xml;
                return true;
            };

            $closeArchive = function () use (&$phar) {
                unset($phar);
            };

        } catch (Throwable $e) {
            @unlink($outputPath);
            throw new RuntimeException(
                'TRACEGRAD could not open the Word template archive: ' . $e->getMessage()
            );
        }

    } else {
        @unlink($outputPath);
        throw new RuntimeException(
            'DOCX export requires either PHP ZipArchive or PharData support.'
        );
    }

    if ($documentXml === false || $documentXml === '') {
        if (is_callable($closeArchive)) {
            $closeArchive();
        }
        @unlink($outputPath);
        throw new RuntimeException('The Word template is missing word/document.xml.');
    }

    if (!preg_match('/<w:sectPr>.*?<\/w:sectPr>/s', $documentXml, $sectionMatch)) {
        if (is_callable($closeArchive)) {
            $closeArchive();
        }
        @unlink($outputPath);
        throw new RuntimeException('The Word template is missing its section definition.');
    }

    $sectionProperties = $sectionMatch[0];

    /* Force every exported Word report to A4 portrait. */
    $sectionProperties = preg_replace(
        '/<w:pgSz[^>]*\/>/',
        '<w:pgSz w:w="11906" w:h="16838"/>',
        $sectionProperties
    );

    /* Leave safe room for the real Word header and footer artwork. */
    $sectionProperties = preg_replace(
        '/<w:pgMar[^>]*\/>/',
        '<w:pgMar w:top="2200" w:right="1800" w:bottom="1900" w:left="1800" w:header="720" w:footer="720" w:gutter="0"/>',
        $sectionProperties
    );

    $newBody = '<w:body>' . $bodyXml . $sectionProperties . '</w:body>';

    $updated = preg_replace_callback(
        '/<w:body>.*?<\/w:body>/s',
        function () use ($newBody) {
            return $newBody;
        },
        $documentXml,
        1
    );

    if ($updated === null || $updated === $documentXml) {
        if (is_callable($closeArchive)) {
            $closeArchive();
        }
        @unlink($outputPath);
        throw new RuntimeException('TRACEGRAD could not insert the report into the Word template.');
    }

    if (!is_callable($writeDocumentXml) || !$writeDocumentXml($updated)) {
        if (is_callable($closeArchive)) {
            $closeArchive();
        }
        @unlink($outputPath);
        throw new RuntimeException('TRACEGRAD could not save the Word report body.');
    }

    if (is_callable($closeArchive)) {
        $closeArchive();
    }

    clearstatcache(true, $outputPath);

    if (!is_file($outputPath) || filesize($outputPath) <= 0) {
        @unlink($outputPath);
        throw new RuntimeException('TRACEGRAD could not generate the Word report.');
    }
}


/* ============================================================
   INPUT
============================================================ */

$template =
    strtolower(
        trim(
            (string)(
                $_GET['template']
                ?? 'department_summary'
            )
        )
    );


/* Backward compatibility with the older Super Admin form. */
if ($template === 'ched_tracer') {
    $template = 'department_summary';
}


$allowedTemplates = [
    'department_summary',
    'employment',
    'roster',
];


if (
    !in_array(
        $template,
        $allowedTemplates,
        true
    )
) {
    $template =
        'department_summary';
}


$format =
    strtolower(
        trim(
            (string)(
                $_GET['format']
                ?? 'html'
            )
        )
    );


if (
    !in_array(
        $format,
        ['html', 'pdf', 'docx', 'csv'],
        true
    )
) {
    $format =
        'html';
}


$collegeId =
    isset($_GET['college_id'])
    &&
    $_GET['college_id'] !== ''
        ? (int)$_GET['college_id']
        : 0;


$batchYear =
    isset($_GET['batch_year'])
    &&
    $_GET['batch_year'] !== ''
        ? (int)$_GET['batch_year']
        : 0;


if (
    $batchYear < 1900
    ||
    $batchYear > 2100
) {
    $batchYear = 0;
}


/* ============================================================
   VALIDATE / RESOLVE DEPARTMENT SCOPE
============================================================ */

$scopeDepartmentName =
    'All Departments';

$scopeDepartmentCode =
    'ALL';


if ($collegeId > 0) {

    $collegeStmt =
        $pdo->prepare(
            "SELECT
                college_id,
                college_code,
                college_name
             FROM colleges
             WHERE college_id = ?
             LIMIT 1"
        );

    $collegeStmt->execute([
        $collegeId
    ]);

    $collegeRow =
        $collegeStmt->fetch();

    if (!$collegeRow) {

        $collegeId = 0;

    } else {

        $scopeDepartmentName =
            (string)$collegeRow['college_name'];

        $scopeDepartmentCode =
            (string)$collegeRow['college_code'];
    }
}


/* ============================================================
   SURVEY QUESTION COUNT
============================================================ */

try {

    $questionCount =
        (int)$pdo->query(
            "SELECT COUNT(*)
             FROM survey_questions
             WHERE status='Active'"
        )->fetchColumn();

} catch (Throwable $e) {

    $questionCount = 0;
}


/* ============================================================
   REPORT DATA
============================================================ */

$reportTitle = '';
$reportSubtitle = '';
$columns = [];
$rows = [];
$rawDepartmentSummary = [];


/* ------------------------------------------------------------
   1. DEPARTMENT SUMMARY
------------------------------------------------------------ */
if ($template === 'department_summary') {

    $reportTitle =
        'INSTITUTIONAL DEPARTMENT SUMMARY';

    $reportSubtitle =
        $collegeId > 0
            ? $scopeDepartmentName
            : 'All Active Departments';


    $graduateJoin =
        "LEFT JOIN graduates g
            ON g.course_id = c.course_id";

    $params = [];


    if ($batchYear > 0) {

        $graduateJoin .=
            " AND g.batch_year = ?";

        $params[] =
            $batchYear;
    }


    $where =
        "WHERE col.status = 'Active'";


    if ($collegeId > 0) {

        $where .=
            " AND col.college_id = ?";

        $params[] =
            $collegeId;
    }


    $sql = "
        SELECT
            col.college_id,
            col.college_code,
            col.college_name,

            (
                SELECT COUNT(*)
                FROM courses pc
                WHERE pc.college_id = col.college_id
                  AND pc.status = 'Active'
            ) AS program_count,

            COUNT(DISTINCT g.graduate_id)
                AS total_alumni,

            COUNT(
                DISTINCT
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM employment ew
                        WHERE ew.graduate_id = g.graduate_id
                          AND ew.employment_status IN (
                              'Employed',
                              'Self-Employed',
                              'Freelancer'
                          )
                    )
                    THEN g.graduate_id
                END
            ) AS working_alumni,

            COUNT(
                DISTINCT
                CASE
                    WHEN COALESCE(sa.answer_count, 0) > 0
                    THEN g.graduate_id
                END
            ) AS survey_responded,

            COUNT(
                DISTINCT
                CASE
                    WHEN " . (int)$questionCount . " > 0
                     AND COALESCE(sa.answer_count, 0) >= " . (int)$questionCount . "
                    THEN g.graduate_id
                END
            ) AS survey_completed,

            COUNT(DISTINCT g.batch_year)
                AS batch_count

        FROM colleges col

        LEFT JOIN courses c
            ON c.college_id = col.college_id

        $graduateJoin

        LEFT JOIN (
            SELECT
                graduate_id,
                COUNT(DISTINCT question_id)
                    AS answer_count
            FROM survey_answers
            GROUP BY graduate_id
        ) sa
            ON sa.graduate_id = g.graduate_id

        $where

        GROUP BY
            col.college_id,
            col.college_code,
            col.college_name

        ORDER BY
            col.college_name ASC
    ";


    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute(
        $params
    );

    $rawDepartmentSummary =
        $stmt->fetchAll();


    $columns = [
        'Department Code',
        'Department',
        'Active Programs',
        'Alumni',
        'Working Alumni',
        'Employment Rate',
        'Survey Responded',
        'Survey Completed',
        'Survey Completion Rate',
        'Batches Represented',
    ];


    foreach (
        $rawDepartmentSummary
        as $row
    ) {

        $total =
            (int)$row['total_alumni'];

        $working =
            (int)$row['working_alumni'];

        $completed =
            (int)$row['survey_completed'];


        $rows[] = [
            $row['college_code'],
            $row['college_name'],
            (int)$row['program_count'],
            $total,
            $working,
            saRate(
                $working,
                $total
            ) . '%',
            (int)$row['survey_responded'],
            $completed,
            saRate(
                $completed,
                $total
            ) . '%',
            (int)$row['batch_count'],
        ];
    }


/* ------------------------------------------------------------
   2. EMPLOYMENT OUTCOMES
------------------------------------------------------------ */
} elseif ($template === 'employment') {

    $reportTitle =
        'INSTITUTIONAL EMPLOYMENT OUTCOMES';

    $reportSubtitle =
        $collegeId > 0
            ? $scopeDepartmentName
            : 'All Departments';


    $where = [];
    $params = [];


    if ($collegeId > 0) {
        $where[] =
            'col.college_id = ?';
        $params[] =
            $collegeId;
    }


    if ($batchYear > 0) {
        $where[] =
            'g.batch_year = ?';
        $params[] =
            $batchYear;
    }


    $whereSql =
        $where
            ? ' WHERE ' . implode(
                ' AND ',
                $where
            )
            : '';


    $sql = "
        SELECT
            g.student_id,

            CONCAT(
                g.lastname,
                ', ',
                g.firstname
            ) AS alumni_name,

            c.course_code,
            c.course_major,

            col.college_code,
            col.college_name,

            g.batch_year,

            COALESCE(
                e.employment_status,
                'No employment record'
            ) AS employment_status,

            COALESCE(
                NULLIF(e.employer_name, ''),
                comp.company_name,
                '—'
            ) AS company,

            COALESCE(
                e.position_title,
                '—'
            ) AS position_title,

            e.monthly_salary,

            COALESCE(
                NULLIF(e.work_region, ''),
                e.work_location,
                '—'
            ) AS work_location

        FROM graduates g

        JOIN courses c
            ON c.course_id = g.course_id

        JOIN colleges col
            ON col.college_id = c.college_id

        LEFT JOIN employment e
            ON e.employment_id = (
                SELECT MAX(e2.employment_id)
                FROM employment e2
                WHERE e2.graduate_id = g.graduate_id
            )

        LEFT JOIN companies comp
            ON comp.company_id = e.company_id

        $whereSql

        ORDER BY
            col.college_name ASC,
            g.batch_year DESC,
            g.lastname ASC,
            g.firstname ASC
    ";


    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute(
        $params
    );

    $data =
        $stmt->fetchAll();


    $columns = [
        'Student ID',
        'Alumni',
        'Program',
        'Department',
        'Batch',
        'Employment Status',
        'Company / Employer',
        'Position',
        'Monthly Salary',
        'Work Location',
    ];


    foreach ($data as $row) {

        $program =
            (string)$row['course_code'];

        if (
            trim(
                (string)($row['course_major'] ?? '')
            ) !== ''
        ) {
            $program .=
                ' — ' .
                trim(
                    (string)$row['course_major']
                );
        }


        $rows[] = [
            $row['student_id'],
            $row['alumni_name'],
            $program,
            $row['college_code'],
            $row['batch_year'],
            $row['employment_status'],
            $row['company'],
            $row['position_title'],
            $row['monthly_salary'] !== null
                ? number_format(
                    (float)$row['monthly_salary'],
                    2,
                    '.',
                    ''
                )
                : '—',
            $row['work_location'],
        ];
    }


/* ------------------------------------------------------------
   3. INSTITUTIONAL ROSTER
------------------------------------------------------------ */
} else {

    $reportTitle =
        'INSTITUTIONAL ALUMNI ROSTER';

    $reportSubtitle =
        $collegeId > 0
            ? $scopeDepartmentName
            : 'All Departments';


    $where = [];
    $params = [];


    if ($collegeId > 0) {
        $where[] =
            'col.college_id = ?';
        $params[] =
            $collegeId;
    }


    if ($batchYear > 0) {
        $where[] =
            'g.batch_year = ?';
        $params[] =
            $batchYear;
    }


    $whereSql =
        $where
            ? ' WHERE ' . implode(
                ' AND ',
                $where
            )
            : '';


    $sql = "
        SELECT
            g.student_id,

            CONCAT(
                g.lastname,
                ', ',
                g.firstname
            ) AS alumni_name,

            c.course_code,
            c.course_major,

            col.college_code,
            col.college_name,

            g.batch_year,

            COALESCE(
                sa.answer_count,
                0
            ) AS answer_count,

            COALESCE(
                (
                    SELECT e3.employment_status
                    FROM employment e3
                    WHERE e3.graduate_id = g.graduate_id
                    ORDER BY e3.employment_id DESC
                    LIMIT 1
                ),
                'No employment record'
            ) AS employment_status

        FROM graduates g

        JOIN courses c
            ON c.course_id = g.course_id

        JOIN colleges col
            ON col.college_id = c.college_id

        LEFT JOIN (
            SELECT
                graduate_id,
                COUNT(DISTINCT question_id)
                    AS answer_count
            FROM survey_answers
            GROUP BY graduate_id
        ) sa
            ON sa.graduate_id = g.graduate_id

        $whereSql

        ORDER BY
            col.college_name ASC,
            g.batch_year DESC,
            g.lastname ASC,
            g.firstname ASC
    ";


    $stmt =
        $pdo->prepare(
            $sql
        );

    $stmt->execute(
        $params
    );

    $data =
        $stmt->fetchAll();


    $columns = [
        'Student ID',
        'Alumni',
        'Program',
        'Department',
        'Batch',
        'Survey Status',
        'Answers',
        'Employment Status',
    ];


    foreach ($data as $row) {

        $program =
            (string)$row['course_code'];

        if (
            trim(
                (string)($row['course_major'] ?? '')
            ) !== ''
        ) {
            $program .=
                ' — ' .
                trim(
                    (string)$row['course_major']
                );
        }


        $rows[] = [
            $row['student_id'],
            $row['alumni_name'],
            $program,
            $row['college_code'],
            $row['batch_year'],
            saSurveyStatus(
                $row['answer_count'],
                $questionCount
            ),
            (int)$row['answer_count'],
            $row['employment_status'],
        ];
    }
}


/* ============================================================
   CSV OUTPUT
============================================================ */

$scopeLabel =
    $collegeId > 0
        ? $scopeDepartmentCode
        : 'All_Departments';


$batchLabel =
    $batchYear > 0
        ? 'Batch_' . $batchYear
        : 'All_Batches';


$filenameBase =
    saSafeFilename(
        'TRACEGRAD_' .
        $reportTitle .
        '_' .
        $scopeLabel .
        '_' .
        $batchLabel
    );


if ($format === 'csv') {

    if (ob_get_length()) {
        ob_clean();
    }

    header(
        'Content-Type: text/csv; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filenameBase .
        '.csv"'
    );

    /*
     * UTF-8 BOM helps Excel display names and special characters.
     */
    echo "\xEF\xBB\xBF";

    $out =
        fopen(
            'php://output',
            'w'
        );

    if ($out === false) {
        http_response_code(500);
        exit(
            'Unable to create CSV output.'
        );
    }


    $safeColumns = [];

    foreach ($columns as $column) {
        $safeColumns[] =
            saCsvSafe($column);
    }

    fputcsv(
        $out,
        $safeColumns
    );


    foreach ($rows as $row) {

        $safeRow = [];

        foreach ($row as $value) {
            $safeRow[] =
                saCsvSafe($value);
        }

        fputcsv(
            $out,
            $safeRow
        );
    }

    fclose($out);
    exit;
}


/* ============================================================
   OFFICIAL ARTWORK
============================================================ */

$artwork =
    saOfficialArtwork();

/* Exact Department Admin header/footer is mandatory. */
if (!$artwork['ready']) {
    http_response_code(500);
    exit(
        'Official ISUFST report header/footer could not be loaded. ' .
        'Make sure the official report artwork files exist inside ' .
        'assets/templates/.'
    );
}


/* ============================================================
   PRINT / PREVIEW SUMMARY VALUES
============================================================ */

$generatedAt =
    date(
        'F d, Y g:i A'
    );

$batchDisplay =
    $batchYear > 0
        ? 'Batch ' . $batchYear
        : 'All Batches';

$preparedBy = trim(
    (string)(
        $_SESSION['admin_name']
        ?? $_SESSION['fullname']
        ?? 'Super Administrator'
    )
);

if ($preparedBy === '') {
    $preparedBy = 'Super Administrator';
}

$reportTypeDisplay =
    $template === 'department_summary'
        ? 'Institutional Department Summary'
        : ($template === 'employment'
            ? 'Institutional Employment Outcomes'
            : 'Institutional Alumni Roster');

$totalResultRows =
    count($rows);


/*
 * KPI values displayed at the beginning of the Department Summary
 * report. These come from exactly the same filtered rows used by
 * the table.
 */
$summaryTotalAlumni = 0;
$summaryWorking = 0;
$summaryResponded = 0;
$summaryCompleted = 0;
$summaryPrograms = 0;


if ($template === 'department_summary') {

    foreach (
        $rawDepartmentSummary
        as $summaryRow
    ) {

        $summaryTotalAlumni +=
            (int)$summaryRow['total_alumni'];

        $summaryWorking +=
            (int)$summaryRow['working_alumni'];

        $summaryResponded +=
            (int)$summaryRow['survey_responded'];

        $summaryCompleted +=
            (int)$summaryRow['survey_completed'];

        $summaryPrograms +=
            (int)$summaryRow['program_count'];
    }
}



/* ============================================================
   OFFICIAL DOCX DOWNLOAD
   ------------------------------------------------------------
   Uses the uploaded Word template directly. This preserves the
   real Word header/footer and forces A4 portrait output.
============================================================ */

if ($format === 'docx') {

    $docxGeneratedAt = date('F d, Y g:i A');

    $docxPreparedBy = trim(
        (string)(
            $_SESSION['admin_name']
            ?? $_SESSION['fullname']
            ?? 'Super Administrator'
        )
    );

    if ($docxPreparedBy === '') {
        $docxPreparedBy = 'Super Administrator';
    }

    $docxBatchDisplay = $batchYear > 0
        ? 'Batch ' . $batchYear
        : 'All Batches';

    $docxScopeDisplay = $collegeId > 0
        ? $scopeDepartmentCode . ' - ' . $scopeDepartmentName
        : 'All Departments';

    $docxReportType = $template === 'department_summary'
        ? 'Institutional Department Summary'
        : ($template === 'employment'
            ? 'Institutional Employment Outcomes'
            : 'Institutional Alumni Roster');

    $docxSummaryRows = [];

    if ($template === 'department_summary') {
        $docxSummaryRows = [
            [
                'Active Departments Represented',
                number_format(count($rawDepartmentSummary)),
                'Departments included in the selected reporting scope.'
            ],
            [
                'Active Programs',
                number_format($summaryPrograms),
                'Programs represented in the selected reporting scope.'
            ],
            [
                'Total Alumni',
                number_format($summaryTotalAlumni),
                'Graduate records included in this report.'
            ],
            [
                'Working Alumni',
                number_format($summaryWorking),
                saRate($summaryWorking, $summaryTotalAlumni) . '% of alumni in the selected scope.'
            ],
            [
                'Survey Responded',
                number_format($summaryResponded),
                'Alumni with recorded tracer survey responses.'
            ],
            [
                'Survey Completed',
                number_format($summaryCompleted),
                saRate($summaryCompleted, $summaryTotalAlumni) . '% of alumni in the selected scope.'
            ],
        ];

    } elseif ($template === 'employment') {

        $workingCount = 0;
        $unemployedCount = 0;
        $missingEmploymentCount = 0;

        foreach ($rows as $docxRow) {
            $status = strtolower(trim((string)($docxRow[5] ?? '')));

            if (in_array($status, ['employed', 'self-employed', 'freelancer'], true)) {
                $workingCount++;
            } elseif ($status === 'unemployed') {
                $unemployedCount++;
            } elseif ($status === 'no employment record') {
                $missingEmploymentCount++;
            }
        }

        $docxTotal = count($rows);

        $docxSummaryRows = [
            [
                'Total Alumni Records',
                number_format($docxTotal),
                'Graduate records included in the employment report.'
            ],
            [
                'Working Alumni',
                number_format($workingCount),
                saRate($workingCount, $docxTotal) . '% recorded as employed, self-employed, or freelancer.'
            ],
            [
                'Unemployed Alumni',
                number_format($unemployedCount),
                saRate($unemployedCount, $docxTotal) . '% recorded as unemployed.'
            ],
            [
                'No Employment Record',
                number_format($missingEmploymentCount),
                'Records that may require employment follow-up.'
            ],
        ];

    } else {

        $completedCount = 0;
        $partialCount = 0;
        $notStartedCount = 0;
        $employmentRecordedCount = 0;

        foreach ($rows as $docxRow) {
            $surveyStatus = strtolower(trim((string)($docxRow[5] ?? '')));
            $employmentStatus = strtolower(trim((string)($docxRow[7] ?? '')));

            if ($surveyStatus === 'completed') {
                $completedCount++;
            } elseif ($surveyStatus === 'partial') {
                $partialCount++;
            } else {
                $notStartedCount++;
            }

            if ($employmentStatus !== '' && $employmentStatus !== 'no employment record') {
                $employmentRecordedCount++;
            }
        }

        $docxTotal = count($rows);

        $docxSummaryRows = [
            [
                'Total Alumni Records',
                number_format($docxTotal),
                'Graduate records included in this roster.'
            ],
            [
                'Survey Completed',
                number_format($completedCount),
                saRate($completedCount, $docxTotal) . '% of the selected roster.'
            ],
            [
                'Survey In Progress',
                number_format($partialCount),
                'Alumni with partial survey responses.'
            ],
            [
                'Survey Not Started',
                number_format($notStartedCount),
                'Alumni without recorded answers in the active survey.'
            ],
            [
                'Employment Record Available',
                number_format($employmentRecordedCount),
                saRate($employmentRecordedCount, $docxTotal) . '% of the selected roster.'
            ],
        ];
    }

    $docxBody = '';

    $docxBody .= saWordP(
        $reportTitle,
        true,
        26,
        '17365D',
        'center',
        0,
        55,
        true
    );

    $docxBody .= saWordP(
        $reportSubtitle,
        false,
        17,
        '556477',
        'center',
        0,
        110,
        true
    );

    $docxBody .= saWordSectionTitle('REPORT INFORMATION');

    $docxBody .= saWordMetaTable([
        ['Report Type', $docxReportType],
        ['Prepared By', $docxPreparedBy],
        ['Date Generated', $docxGeneratedAt],
        ['Records / Rows', number_format(count($rows))],
        ['Department Scope', $docxScopeDisplay],
        ['Graduate Batch', $docxBatchDisplay],
        ['Source System', 'TRACEGRAD'],
        ['Page Layout', 'A4 Portrait'],
    ]);

    $docxBody .= saWordSectionTitle('SUMMARY OF RESULTS');
    $docxBody .= saWordSummaryTable($docxSummaryRows);

    $docxBody .= saWordSectionTitle('DETAILED RESULTS');

    $docxWidths = saWordDetailedWidths($template, $columns);
    $docxTableRows = [];
    $docxFontSize = count($columns) >= 10 ? 12 : 13;

    $headerCells = [
        saWordCell(
            saWordP('No.', true, 12, 'FFFFFF', 'center', 0, 0),
            $docxWidths[0],
            '17365D',
            'center'
        )
    ];

    $docxHeaderMap = [
        'Department Code' => 'Dept. Code',
        'Department' => 'Dept.',
        'Active Programs' => 'Programs',
        'Working Alumni' => 'Working',
        'Employment Rate' => 'Employment Rate',
        'Survey Responded' => 'Responded',
        'Survey Completed' => 'Completed',
        'Survey Completion Rate' => 'Survey Rate',
        'Batches Represented' => 'Batches',
        'Employment Status' => 'Status',
        'Company / Employer' => 'Employer / Company',
        'Monthly Salary' => 'Salary',
    ];

    foreach ($columns as $columnIndex => $columnName) {
        $docxColumnHeading = isset($docxHeaderMap[$columnName])
            ? $docxHeaderMap[$columnName]
            : $columnName;

        $headerCells[] = saWordCell(
            saWordP($docxColumnHeading, true, 12, 'FFFFFF', 'center', 0, 0),
            $docxWidths[$columnIndex + 1],
            '17365D',
            'center'
        );
    }

    $docxTableRows[] = saWordRow($headerCells, true);

    if ($rows) {
        $docxRowNumber = 0;

        foreach ($rows as $docxRow) {
            $docxRowNumber++;
            $rowShade = ($docxRowNumber % 2 === 0) ? 'F8FAFC' : '';

            $cells = [
                saWordCell(
                    saWordP((string)$docxRowNumber, true, $docxFontSize, '526477', 'center', 0, 0),
                    $docxWidths[0],
                    $rowShade,
                    'center'
                )
            ];

            foreach ($docxRow as $columnIndex => $value) {
                $columnName = (string)($columns[$columnIndex] ?? '');
                $valueText = (string)$value;
                $numericColumns = [
                    'Active Programs', 'Alumni', 'Working Alumni',
                    'Employment Rate', 'Survey Responded', 'Survey Completed',
                    'Survey Completion Rate', 'Batches Represented',
                    'Batch', 'Monthly Salary', 'Answers'
                ];

                $align = in_array($columnName, $numericColumns, true)
                    ? 'center'
                    : 'left';

                $cells[] = saWordCell(
                    saWordP(
                        $valueText,
                        false,
                        $docxFontSize,
                        saWordStatusColor($valueText),
                        $align,
                        0,
                        0
                    ),
                    $docxWidths[$columnIndex + 1],
                    $rowShade,
                    $align
                );
            }

            $docxTableRows[] = saWordRow($cells);
        }

    } else {
        $cells = [];
        foreach ($docxWidths as $index => $width) {
            $cells[] = saWordCell(
                saWordP(
                    $index === 0
                        ? 'No records match the selected report filters.'
                        : '',
                    false,
                    13,
                    '64748B',
                    'center',
                    0,
                    0
                ),
                $width,
                'F8FAFC',
                'center'
            );
        }
        $docxTableRows[] = saWordRow($cells);
    }

    $docxBody .= saWordTable($docxTableRows, $docxWidths);

    $docxBody .= saWordP(
        'Document Control: This report was generated by TRACEGRAD for authorized institutional alumni and tracer-study reporting. Values reflect records stored in TRACEGRAD and the reporting scope selected by the Super Administrator.',
        false,
        12,
        '667085',
        'left',
        120,
        40
    );

    $docxBody .= saWordP(
        'Generated by TRACEGRAD System - ' . date('Y-m-d H:i:s'),
        false,
        11,
        '7C8795',
        'right',
        0,
        0
    );

    $docxTemplatePath = __DIR__ . '/assets/templates/super-admin-report-template.docx';

    $tmpBase = tempnam(sys_get_temp_dir(), 'tracegrad_super_report_');

    if ($tmpBase === false) {
        http_response_code(500);
        exit('Unable to create the temporary Microsoft Word report.');
    }

    $tmpDocx = $tmpBase . '.docx';
    @unlink($tmpBase);

    try {
        saCreateOfficialDocx(
            $docxTemplatePath,
            $tmpDocx,
            $docxBody
        );
    } catch (Throwable $e) {
        @unlink($tmpDocx);
        tgErrorLogThrowable($e, 'Super Admin DOCX export failed');
        http_response_code(500);
        exit(saReportEsc(tgPublicExceptionMessage($e, 'The DOCX report could not be generated.')));
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    @ini_set('zlib.output_compression', 'Off');

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.docx"');
    header('Content-Length: ' . filesize($tmpDocx));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($tmpDocx);
    @unlink($tmpDocx);
    exit;
}

/* ============================================================
   FORMAL DETAILED RESULTS TABLE
============================================================ */

$tableWidths = [];

if ($template === 'department_summary') {
    $tableWidths = [
        '4%', '8%', '18%', '7%', '7%', '8%',
        '8%', '8%', '8%', '8%', '8%'
    ];
} elseif ($template === 'employment') {
    $tableWidths = [
        '4%', '8%', '13%', '10%', '7%', '6%',
        '9%', '12%', '10%', '8%', '13%'
    ];
} else {
    $tableWidths = [
        '4%', '10%', '18%', '13%', '8%',
        '7%', '10%', '8%', '12%'
    ];
}

$numericColumnNames = [
    'Active Programs',
    'Alumni',
    'Working Alumni',
    'Employment Rate',
    'Survey Responded',
    'Survey Completed',
    'Survey Completion Rate',
    'Batches Represented',
    'Batch',
    'Monthly Salary',
    'Answers',
];

$tableHtml =
    '<section class="report-section detailed-results-section">' .
    '<div class="section-title-row">' .
        '<h2>DETAILED RESULTS</h2>' .
        '<span>' . number_format($totalResultRows) . ' row' .
        ($totalResultRows === 1 ? '' : 's') . '</span>' .
    '</div>' .
    '<div class="table-wrap">' .
    '<table class="report-table">' .
    '<colgroup>';

foreach ($tableWidths as $width) {
    $tableHtml .= '<col style="width:' . saReportEsc($width) . '">';
}

$tableHtml .=
    '</colgroup>' .
    '<thead><tr>' .
    '<th class="row-number-head">No.</th>';

$htmlHeaderMap = [
    'Department Code' => 'Dept. Code',
    'Department' => 'Dept.',
    'Active Programs' => 'Programs',
    'Working Alumni' => 'Working',
    'Survey Responded' => 'Responded',
    'Survey Completed' => 'Completed',
    'Survey Completion Rate' => 'Survey Rate',
    'Batches Represented' => 'Batches',
    'Employment Status' => 'Status',
    'Company / Employer' => 'Employer / Company',
    'Monthly Salary' => 'Salary',
];

foreach ($columns as $column) {
    $headerClass = in_array($column, $numericColumnNames, true)
        ? ' class="numeric-head"'
        : '';

    $displayColumn = isset($htmlHeaderMap[$column])
        ? $htmlHeaderMap[$column]
        : $column;

    $tableHtml .=
        '<th' . $headerClass . ' title="' . saReportEsc($column) . '">' .
        saReportEsc($displayColumn) .
        '</th>';
}

$tableHtml .= '</tr></thead><tbody>';

if ($rows) {
    $rowNumber = 0;

    foreach ($rows as $row) {
        $rowNumber++;
        $tableHtml .= '<tr>';
        $tableHtml .=
            '<td class="row-number-cell">' .
            number_format($rowNumber) .
            '</td>';

        foreach ($row as $columnIndex => $value) {
            $cellText = (string)$value;
            $cellClasses = [];
            $lower = strtolower(trim($cellText));
            $columnName = isset($columns[$columnIndex])
                ? (string)$columns[$columnIndex]
                : '';

            if (in_array($columnName, $numericColumnNames, true)) {
                $cellClasses[] = 'numeric-cell';
            }

            if (
                in_array(
                    $lower,
                    ['completed', 'employed', 'self-employed', 'freelancer'],
                    true
                )
            ) {
                $cellClasses[] = 'status-good';
            } elseif (
                in_array($lower, ['partial', 'underemployed'], true)
            ) {
                $cellClasses[] = 'status-warning';
            } elseif (
                in_array(
                    $lower,
                    ['not started', 'unemployed', 'no employment record'],
                    true
                )
            ) {
                $cellClasses[] = 'status-danger';
            }

            $classAttribute = $cellClasses
                ? ' class="' . implode(' ', $cellClasses) . '"'
                : '';

            $tableHtml .=
                '<td' . $classAttribute . '>' .
                saReportEsc($cellText) .
                '</td>';
        }

        $tableHtml .= '</tr>';
    }
} else {
    $tableHtml .=
        '<tr>' .
        '<td colspan="' . max(1, count($columns) + 1) .
        '" class="empty-cell">' .
        'No records match the selected report filters.' .
        '</td>' .
        '</tr>';
}

$tableHtml .=
    '</tbody></table></div></section>';


/* ============================================================
   FORMAL SUMMARY TABLE
============================================================ */

$summaryHtml = '';

if ($template === 'department_summary') {
    $summaryHtml =
        '<section class="report-section summary-section">' .
        '<div class="section-title-row">' .
            '<h2>SUMMARY OF RESULTS</h2>' .
            '<span>Institutional overview</span>' .
        '</div>' .
        '<table class="summary-table">' .
        '<thead><tr>' .
            '<th>Indicator</th>' .
            '<th>Count / Value</th>' .
            '<th>Rate / Interpretation</th>' .
        '</tr></thead>' .
        '<tbody>' .
            '<tr><td>Active Departments Represented</td><td>' .
                number_format(count($rawDepartmentSummary)) .
                '</td><td>Departments included in the selected scope</td></tr>' .
            '<tr><td>Active Programs</td><td>' .
                number_format($summaryPrograms) .
                '</td><td>Programs represented in the report</td></tr>' .
            '<tr><td>Total Alumni</td><td>' .
                number_format($summaryTotalAlumni) .
                '</td><td>Graduate records in the selected scope</td></tr>' .
            '<tr><td>Working Alumni</td><td>' .
                number_format($summaryWorking) .
                '</td><td>' .
                saRate($summaryWorking, $summaryTotalAlumni) .
                '% of alumni</td></tr>' .
            '<tr><td>Survey Responded</td><td>' .
                number_format($summaryResponded) .
                '</td><td>Alumni with recorded tracer survey responses</td></tr>' .
            '<tr><td>Survey Completed</td><td>' .
                number_format($summaryCompleted) .
                '</td><td>' .
                saRate($summaryCompleted, $summaryTotalAlumni) .
                '% of alumni</td></tr>' .
        '</tbody></table></section>';

} elseif ($template === 'employment') {

    $employmentTotal = count($rows);
    $employmentWorking = 0;
    $employmentUnemployed = 0;
    $employmentMissing = 0;

    foreach ($rows as $summaryRow) {
        $status = strtolower(trim((string)($summaryRow[5] ?? '')));
        if (in_array($status, ['employed', 'self-employed', 'freelancer'], true)) {
            $employmentWorking++;
        } elseif ($status === 'unemployed') {
            $employmentUnemployed++;
        } elseif ($status === 'no employment record') {
            $employmentMissing++;
        }
    }

    $summaryHtml =
        '<section class="report-section summary-section">' .
        '<div class="section-title-row"><h2>SUMMARY OF RESULTS</h2><span>Employment overview</span></div>' .
        '<table class="summary-table"><thead><tr><th>Indicator</th><th>Count / Value</th><th>Rate / Interpretation</th></tr></thead><tbody>' .
        '<tr><td>Total Alumni Records</td><td>' . number_format($employmentTotal) . '</td><td>Graduate records included in this report</td></tr>' .
        '<tr><td>Working Alumni</td><td>' . number_format($employmentWorking) . '</td><td>' . saRate($employmentWorking, $employmentTotal) . '% recorded as employed, self-employed, or freelancer</td></tr>' .
        '<tr><td>Unemployed Alumni</td><td>' . number_format($employmentUnemployed) . '</td><td>' . saRate($employmentUnemployed, $employmentTotal) . '% recorded as unemployed</td></tr>' .
        '<tr><td>No Employment Record</td><td>' . number_format($employmentMissing) . '</td><td>Records that may require employment follow-up</td></tr>' .
        '</tbody></table></section>';

} else {

    $rosterTotalSummary = count($rows);
    $rosterCompletedSummary = 0;
    $rosterPartialSummary = 0;
    $rosterNotStartedSummary = 0;
    $rosterEmploymentSummary = 0;

    foreach ($rows as $summaryRow) {
        $surveyStatus = strtolower(trim((string)($summaryRow[5] ?? '')));
        $employmentStatus = strtolower(trim((string)($summaryRow[7] ?? '')));

        if ($surveyStatus === 'completed') {
            $rosterCompletedSummary++;
        } elseif ($surveyStatus === 'partial') {
            $rosterPartialSummary++;
        } else {
            $rosterNotStartedSummary++;
        }

        if ($employmentStatus !== '' && $employmentStatus !== 'no employment record') {
            $rosterEmploymentSummary++;
        }
    }

    $summaryHtml =
        '<section class="report-section summary-section">' .
        '<div class="section-title-row"><h2>SUMMARY OF RESULTS</h2><span>Roster overview</span></div>' .
        '<table class="summary-table"><thead><tr><th>Indicator</th><th>Count / Value</th><th>Rate / Interpretation</th></tr></thead><tbody>' .
        '<tr><td>Total Alumni Records</td><td>' . number_format($rosterTotalSummary) . '</td><td>Graduate records included in this roster</td></tr>' .
        '<tr><td>Survey Completed</td><td>' . number_format($rosterCompletedSummary) . '</td><td>' . saRate($rosterCompletedSummary, $rosterTotalSummary) . '% of the selected roster</td></tr>' .
        '<tr><td>Survey In Progress</td><td>' . number_format($rosterPartialSummary) . '</td><td>Alumni with partial survey responses</td></tr>' .
        '<tr><td>Survey Not Started</td><td>' . number_format($rosterNotStartedSummary) . '</td><td>Alumni without recorded answers in the active survey</td></tr>' .
        '<tr><td>Employment Record Available</td><td>' . number_format($rosterEmploymentSummary) . '</td><td>' . saRate($rosterEmploymentSummary, $rosterTotalSummary) . '% of the selected roster</td></tr>' .
        '</tbody></table></section>';
}


/* ============================================================
   OFFICIAL HEADER / FOOTER HTML
============================================================ */

$leftLogoHtml =
    '<img class="official-header-logo-left" src="' .
    saReportEsc($artwork['left']) .
    '" alt="ISUFST">';

$rightLogoHtml =
    '<img class="official-header-logo-right" src="' .
    saReportEsc($artwork['right']) .
    '" alt="Bagong Pilipinas">';

$footerHtml =
    '<img src="' .
    saReportEsc($artwork['footer']) .
    '" alt="ISUFST institutional recognitions and core values">';

$flatHeaderHtml = $artwork['header_flat'] !== ''
    ? '<img class="official-header-flat" src="' . saReportEsc($artwork['header_flat']) . '" alt="Official ISUFST report header">'
    : '';

$flatFooterHtml = $artwork['footer_flat'] !== ''
    ? '<img class="official-footer-flat" src="' . saReportEsc($artwork['footer_flat']) . '" alt="Official ISUFST report footer">'
    : $footerHtml;


/* ============================================================
   PAGE MODE — EXACTLY MATCH DEPARTMENT ADMIN
============================================================ */
$isWideReport = count($columns) >= 8;
$pageSize = 'A4 portrait';
$reportTableFontSize = $isWideReport ? '6.25pt' : '7.4pt';


/* ============================================================
   HTML OUTPUT
============================================================ */

if (ob_get_length()) {
    ob_clean();
}


header(
    'Content-Type: text/html; charset=utf-8'
);

header(
    'Content-Disposition: inline; filename="' .
    $filenameBase .
    '.html"'
);


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>
<title><?= saReportEsc($reportTitle) ?></title>

<style>
:root {
    --navy:#17365d;
    --blue:#0070c0;
    --line:#b8c8d8;
    --line-dark:#8196aa;
    --soft:#f5f8fb;
    --soft-blue:#edf4fa;
    --text:#1f2937;
    --muted:#64748b;
    --good:#176b3a;
    --good-bg:#edf8f1;
    --warning:#8a6400;
    --warning-bg:#fff7db;
    --danger:#a13030;
    --danger-bg:#fff0f0;
}
* { box-sizing:border-box; }
html, body {
    margin:0;
    padding:0;
    background:#e8edf3;
    color:var(--text);
    font-family:Arial, Helvetica, sans-serif;
    font-size:10pt;
    line-height:1.4;
}
.preview-toolbar {
    position:sticky;
    top:0;
    z-index:100;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:11px 18px;
    background:#102d52;
    color:#fff;
    box-shadow:0 4px 14px rgba(0,0,0,.14);
}
.preview-toolbar-copy strong,
.preview-toolbar-copy span { display:block; }
.preview-toolbar-copy strong { font-size:13px; }
.preview-toolbar-copy span {
    margin-top:2px;
    color:#dce8f5;
    font-size:10px;
}
.preview-toolbar-actions { display:flex; flex-wrap:wrap; gap:8px; }
.preview-toolbar button,
.preview-toolbar a {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:35px;
    padding:7px 13px;
    border:1px solid rgba(255,255,255,.22);
    border-radius:6px;
    background:rgba(255,255,255,.1);
    color:#fff;
    font:700 11px Arial,sans-serif;
    text-decoration:none;
    cursor:pointer;
}
.preview-toolbar button:hover,
.preview-toolbar a:hover { background:rgba(255,255,255,.18); }
.report-page {
    position:relative;
    width:210mm;
    min-height:297mm;
    margin:18px auto;
    padding:43mm 12mm 33mm;
    background:#fff;
    box-shadow:0 8px 35px rgba(15,23,42,.14);
}
.official-header {
    position:absolute;
    left:9mm;
    right:9mm;
    top:4mm;
    height:35mm;
    display:block;
    overflow:hidden;
}
.official-header-flat {
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center top;
}
.official-header-composite {
    width:100%;
    height:100%;
    display:grid;
    grid-template-columns:31mm minmax(0,1fr) 29mm;
    align-items:center;
    gap:3mm;
    padding-bottom:2.5mm;
    border-bottom:1.2px solid #5b9bd5;
}
.official-header-logo-left {
    width:29mm;
    height:21mm;
    object-fit:contain;
    justify-self:start;
}
.official-header-logo-right {
    width:25mm;
    height:20mm;
    object-fit:contain;
    justify-self:end;
}
.official-header-copy { text-align:center; line-height:1.18; }
.official-header-copy .republic { font-size:8.3pt; color:#111; }
.official-header-copy .university {
    margin-top:1mm;
    color:#0070c0;
    font-size:10pt;
    font-weight:700;
    white-space:nowrap;
}
.official-header-copy .office {
    margin-top:.7mm;
    color:#111;
    font-size:10.4pt;
    font-weight:700;
}
.official-header-copy .contact,
.official-header-copy .website {
    margin-top:.45mm;
    color:#222;
    font-size:8pt;
}
.official-footer {
    position:absolute;
    left:7mm;
    right:7mm;
    bottom:3mm;
    height:27mm;
    display:block;
    overflow:hidden;
}
.official-footer-flat,
.official-footer img {
    display:block;
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center bottom;
}
.document-heading {
    margin-bottom:5mm;
    text-align:center;
}
.report-title {
    margin:0;
    color:var(--navy);
    font-size:14.5pt;
    font-weight:700;
    letter-spacing:.02em;
    text-transform:uppercase;
}
.report-subtitle {
    margin:1.2mm 0 0;
    color:#556477;
    font-size:9.5pt;
}
.report-section { margin-top:4mm; }
.section-title-row {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin:0 0 1.7mm;
}
.section-title-row h2 {
    margin:0;
    color:var(--navy);
    font-size:9.3pt;
    font-weight:700;
    letter-spacing:.04em;
}
.section-title-row span {
    color:var(--muted);
    font-size:7.5pt;
}
.report-meta,
.summary-table,
.report-table {
    width:100%;
    border-collapse:collapse;
}
.report-meta {
    table-layout:fixed;
    font-size:8.2pt;
}
.report-meta td {
    padding:2.2mm 2.5mm;
    border:1px solid var(--line);
    background:#fbfcfe;
    vertical-align:top;
}
.report-meta span,
.report-meta strong { display:block; }
.report-meta span {
    color:#536579;
    font-size:7pt;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.025em;
}
.report-meta strong {
    margin-top:.6mm;
    color:#1f2937;
    font-size:8.2pt;
    font-weight:700;
}
.summary-table {
    table-layout:fixed;
    font-size:8pt;
}
.summary-table th,
.summary-table td {
    padding:2mm 2.4mm;
    border:1px solid var(--line);
    vertical-align:top;
}
.summary-table th {
    background:#d9eaf7;
    color:#17365d;
    text-align:left;
    font-weight:700;
}
.summary-table th:nth-child(1) { width:42%; }
.summary-table th:nth-child(2) { width:20%; text-align:center; }
.summary-table th:nth-child(3) { width:38%; }
.summary-table td:nth-child(2) {
    text-align:center;
    color:#17365d;
    font-weight:700;
}
.summary-table tbody tr:nth-child(even) { background:#f9fbfd; }
.table-wrap { width:100%; overflow:visible; }
.report-table {
    table-layout:fixed;
    font-size:<?= saReportEsc($reportTableFontSize) ?>;
}
.report-table th,
.report-table td {
    padding:1.2mm 1.05mm;
    border:1px solid var(--line);
    vertical-align:top;
    overflow-wrap:anywhere;
    word-break:break-word;
    hyphens:auto;
}
.report-table th {
    background:#17365d;
    color:#fff;
    text-align:left;
    font-weight:700;
    line-height:1.25;
}
.report-table th.numeric-head,
.report-table td.numeric-cell,
.report-table .row-number-head,
.report-table .row-number-cell {
    text-align:center;
}
.report-table .row-number-cell {
    color:#526477;
    background:#f6f9fc;
    font-weight:700;
}
.report-table tbody tr:nth-child(even) { background:#f8fbfe; }
.report-table tbody tr:hover { background:inherit; }
.report-table td.status-good {
    color:var(--good);
    background:var(--good-bg);
    font-weight:700;
}
.report-table td.status-warning {
    color:var(--warning);
    background:var(--warning-bg);
    font-weight:700;
}
.report-table td.status-danger {
    color:var(--danger);
    background:var(--danger-bg);
    font-weight:700;
}
.empty-cell {
    padding:4mm !important;
    background:#fafbfc;
    color:#777;
    text-align:center;
    font-size:8.5pt;
}
.document-control {
    margin-top:4mm;
    padding:2.4mm 2.8mm;
    border:1px solid #d9e1ea;
    background:#f8fafc;
    color:#667085;
    font-size:7.3pt;
    line-height:1.45;
}
.document-control strong { color:#45576b; }
.generated-note {
    margin:1.7mm 0 0;
    color:#7c8795;
    text-align:right;
    font-size:6.8pt;
}
.official-template-note {
    margin-top:1.2mm;
    color:#7c8795;
    text-align:right;
    font-size:6.5pt;
}
@media print {
    @page {
        size:A4 portrait;
        margin:43mm 12mm 33mm 12mm;
    }
    html, body { background:#fff; }
    body { margin:0; padding:0; }
    .preview-toolbar { display:none !important; }
    .report-page {
        width:auto;
        min-height:0;
        margin:0;
        padding:0;
        box-shadow:none;
    }
    .official-header {
        position:fixed;
        left:0;
        right:0;
        top:-39mm;
        height:35mm;
    }
    .official-footer {
        position:fixed;
        left:-5mm;
        right:-5mm;
        bottom:-29mm;
        height:27mm;
    }
    thead { display:table-header-group; }
    tfoot { display:table-footer-group; }
    .summary-table tr,
    .report-table tr,
    .document-control,
    .section-title-row {
        break-inside:avoid;
        page-break-inside:avoid;
    }
    a { color:inherit; text-decoration:none; }
}
@media screen and (max-width:1050px) {
    body { background:#fff; }
    .preview-toolbar {
        position:relative;
        align-items:flex-start;
        flex-direction:column;
    }
    .report-page {
        width:100%;
        min-height:0;
        margin:0;
        padding:43mm 10px 33mm;
        box-shadow:none;
    }
    .official-header {
        left:10px;
        right:10px;
        grid-template-columns:70px 1fr 65px;
        gap:4px;
    }
    .official-header-logo-left { width:68px; }
    .official-header-logo-right { width:58px; }
    .official-header-copy .university {
        font-size:7.5pt;
        white-space:normal;
    }
    .official-header-copy .office { font-size:8pt; }
    .official-header-copy .contact,
    .official-header-copy .website,
    .official-header-copy .republic { font-size:6.5pt; }
    .official-footer { left:8px; right:8px; }
    .table-wrap { overflow:auto; }
    .report-table { min-width:980px; }
    .report-meta,
    .report-meta tbody,
    .report-meta tr,
    .report-meta td {
        display:block;
        width:100%;
    }
    .report-meta td { border-bottom:0; }
    .report-meta td:last-child { border-bottom:1px solid var(--line); }
}
</style>
</head>

<body>

<div class="preview-toolbar no-print">
    <div class="preview-toolbar-copy">
        <strong>TRACEGRAD Official Report Preview</strong>
        <span>Print / PDF uses the official ISUFST header/footer in A4 portrait. DOCX uses the original Word header and footer.</span>
    </div>

    <div class="preview-toolbar-actions">

        <a
            href="admin-report-export.php?<?= saReportEsc(
                http_build_query(
                    array_merge(
                        $_GET,
                        ['format' => 'docx']
                    )
                )
            ) ?>"
        >
            Download DOCX
        </a>

        <a
            href="admin-report-export.php?<?= saReportEsc(
                http_build_query(
                    array_merge(
                        $_GET,
                        ['format' => 'csv']
                    )
                )
            ) ?>"
        >
            Download CSV
        </a>

        <button
            type="button"
            class="primary-action"
            onclick="window.print()"
        >
            Print / Save PDF
        </button>

        <button
            type="button"
            onclick="window.close()"
        >
            Close
        </button>

    </div>
</div>


<main class="report-page">

    <header class="official-header">
        <?php if ($flatHeaderHtml !== ''): ?>
            <?= $flatHeaderHtml ?>
        <?php else: ?>
            <div class="official-header-composite">
                <?= $leftLogoHtml ?>
                <div class="official-header-copy">
                    <div class="republic">Republic of the Philippines</div>
                    <div class="university">ILOILO STATE UNIVERSITY OF FISHERIES SCIENCE AND TECHNOLOGY</div>
                    <div class="office">ADMISSION AND STUDENT RECORDS OFFICE</div>
                    <div class="contact">San Enrique, Iloilo | Email: sanenriquecampus@gmail.com</div>
                    <div class="website">Website: www.isufst.edu.ph | Contact No: (033) 327-3405</div>
                </div>
                <?= $rightLogoHtml ?>
            </div>
        <?php endif; ?>
    </header>

    <footer class="official-footer">
        <?= $flatFooterHtml ?>
    </footer>


    <div class="document-heading">
        <h1 class="report-title"><?= saReportEsc($reportTitle) ?></h1>
        <div class="report-subtitle"><?= saReportEsc($reportSubtitle) ?></div>
    </div>

    <section class="report-section report-information-section">
        <div class="section-title-row">
            <h2>REPORT INFORMATION</h2>
            <span>TRACEGRAD institutional document</span>
        </div>

        <table class="report-meta">
            <tbody>
                <tr>
                    <td>
                        <span>Report Type</span>
                        <strong><?= saReportEsc($reportTypeDisplay) ?></strong>
                    </td>
                    <td>
                        <span>Prepared By</span>
                        <strong><?= saReportEsc($preparedBy) ?></strong>
                    </td>
                    <td>
                        <span>Date Generated</span>
                        <strong><?= saReportEsc($generatedAt) ?></strong>
                    </td>
                    <td>
                        <span>Records / Rows</span>
                        <strong><?= number_format($totalResultRows) ?></strong>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <span>Department Scope</span>
                        <strong>
                            <?= saReportEsc(
                                $collegeId > 0
                                    ? $scopeDepartmentCode . ' — ' . $scopeDepartmentName
                                    : 'All Departments'
                            ) ?>
                        </strong>
                    </td>
                    <td>
                        <span>Graduate Batch</span>
                        <strong><?= saReportEsc($batchDisplay) ?></strong>
                    </td>
                    <td>
                        <span>Source System</span>
                        <strong>TRACEGRAD</strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <?= $summaryHtml ?>
    <?= $tableHtml ?>

    <div class="document-control">
        <strong>Document Control:</strong>
        This report was generated by TRACEGRAD for authorized institutional
        alumni and tracer-study reporting. Values reflect the records currently
        stored in TRACEGRAD and the reporting scope selected by the Super
        Administrator. Handle personal information in accordance with applicable
        institutional privacy and records-management policies.
    </div>

    <div class="generated-note">
        Generated by TRACEGRAD System · <?= saReportEsc(date('Y-m-d H:i:s')) ?>
    </div>
    <div class="official-template-note">
        Official template: <?= saReportEsc($artwork['template']) ?>
    </div>

</main>


<?php if ($format === 'pdf'): ?>
<script>
window.addEventListener(
    'load',
    function () {
        setTimeout(
            function () {
                window.print();
            },
            250
        );
    }
);
</script>
<?php endif; ?>

</body>
</html>