<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD - Unified Official DOCX Report Export
 * ------------------------------------------------------------
 * Supports:
 *   individual_survey
 *   batch_survey_summary
 *   full_roster_survey
 *
 * Uses the supplied ISUFST DOCX template and preserves its header/footer.
 * PHP 7.2+
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';
require_once __DIR__ . '/includes/dept-admin-dashboard/reports/report-data.php';

$currentAdmin = tgRequireCurrentAdminSession($pdo, 'admin-login.php');
if ((int)$currentAdmin['role_id'] !== 2) {
    header('Location: admin-dashboard.php');
    exit;
}
$collegeId = (int)$currentAdmin['college_id'];
if ($collegeId <= 0) {
    http_response_code(403);
    exit('Your Department Admin account is not assigned to a college.');
}


if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit(
        tgErrorIsLocalRequest()
            ? 'DOCX export requires the PHP ZIP extension. Enable extension=zip in XAMPP php.ini and restart Apache.'
            : 'DOCX export is temporarily unavailable. Reference: ' . tgErrorRequestId()
    );
}

$request = tg_report_request($_GET);

try {
    $package = tg_report_build($pdo, $collegeId, $request);
} catch (Throwable $e) {
    tgErrorLogThrowable($e, 'Department report build failed');
    http_response_code(400);
    exit(htmlspecialchars(tgPublicExceptionMessage($e, 'The requested report could not be prepared.'), ENT_QUOTES, 'UTF-8'));
}

$templateCandidates = [
    __DIR__ . '/assets/templates/survey-report-template.docx',
    __DIR__ . '/header and footer format.docx'
];

$templatePath = '';
foreach ($templateCandidates as $candidate) {
    if (is_file($candidate)) {
        $templatePath = $candidate;
        break;
    }
}

if ($templatePath === '') {
    http_response_code(500);
    exit(
        'Official report template not found. Place survey-report-template.docx ' .
        'inside assets/templates/.'
    );
}

/* ================= WORD HELPERS ================= */

function tg_word_xml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function tg_word_clean($value)
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
    return preg_replace('/[^\P{C}\n\t]/u', '', $value);
}

function tg_word_run($text, $bold = false, $size = 16, $color = '000000')
{
    $parts = explode("\n", tg_word_clean($text));
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
            '<w:color w:val="' . tg_word_xml($color) . '"/>' .
            '</w:rPr><w:t xml:space="preserve">' .
            tg_word_xml($part) .
            '</w:t></w:r>';
    }

    return $xml;
}

function tg_word_p($text = '', $bold = false, $size = 16, $color = '000000', $align = '', $before = 0, $after = 50, $keepNext = false, $bottomBorder = '')
{
    $pPr = '<w:pPr>';

    if ($align !== '') {
        $pPr .= '<w:jc w:val="' . tg_word_xml($align) . '"/>';
    }

    $pPr .= '<w:spacing w:before="' . (int)$before . '" w:after="' . (int)$after . '"/>';

    if ($keepNext) {
        $pPr .= '<w:keepNext/>';
    }

    if ($bottomBorder !== '') {
        $pPr .= '<w:pBdr><w:bottom w:val="single" w:sz="8" w:space="4" w:color="' .
            tg_word_xml($bottomBorder) . '"/></w:pBdr>';
    }

    $pPr .= '</w:pPr>';

    return '<w:p>' . $pPr . tg_word_run($text, $bold, $size, $color) . '</w:p>';
}

function tg_word_section($text)
{
    return tg_word_p($text, true, 20, '17365D', '', 110, 55, true, '5B9BD5');
}

function tg_word_cell($content, $width, $shade = '', $span = 0, $border = 'D9E2F3')
{
    $pr = '<w:tcPr><w:tcW w:w="' . (int)$width . '" w:type="dxa"/>';

    if ($span > 0) {
        $pr .= '<w:gridSpan w:val="' . (int)$span . '"/>';
    }

    if ($shade !== '') {
        $pr .= '<w:shd w:fill="' . tg_word_xml($shade) . '"/>';
    }

    $pr .= '<w:vAlign w:val="center"/>';

    if ($border !== '') {
        $pr .= '<w:tcBorders>' .
            '<w:top w:val="single" w:sz="4" w:color="' . tg_word_xml($border) . '"/>' .
            '<w:left w:val="single" w:sz="4" w:color="' . tg_word_xml($border) . '"/>' .
            '<w:bottom w:val="single" w:sz="4" w:color="' . tg_word_xml($border) . '"/>' .
            '<w:right w:val="single" w:sz="4" w:color="' . tg_word_xml($border) . '"/>' .
            '</w:tcBorders>';
    }

    $pr .= '</w:tcPr>';

    return '<w:tc>' . $pr . $content . '</w:tc>';
}

function tg_word_row($cells, $header = false, $cantSplit = true)
{
    return '<w:tr><w:trPr>' .
        ($header ? '<w:tblHeader/>' : '') .
        ($cantSplit ? '<w:cantSplit/>' : '') .
        '</w:trPr>' .
        implode('', $cells) .
        '</w:tr>';
}

function tg_word_table($rows, $widths)
{
    $grid = '';
    foreach ($widths as $width) {
        $grid .= '<w:gridCol w:w="' . (int)$width . '"/>';
    }

    return '<w:tbl>' .
        '<w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="fixed"/>' .
        '<w:tblCellMar><w:top w:w="45" w:type="dxa"/><w:left w:w="90" w:type="dxa"/>' .
        '<w:bottom w:w="45" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar>' .
        '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>' .
        implode('', $rows) .
        '</w:tbl>';
}

function tg_word_label($label, $width)
{
    return tg_word_cell(tg_word_p($label, true, 15, '44546A', '', 0, 0), $width, 'F2F6FA');
}

function tg_word_value($value, $width, $bold = false, $color = '000000')
{
    return tg_word_cell(tg_word_p($value, $bold, 15, $color, '', 0, 0), $width);
}

function tg_word_status_color($status)
{
    $status = strtolower(trim((string)$status));

    if (in_array($status, ['completed','employed','submitted','active','yes'], true)) {
        return '237044';
    }
    if (in_array($status, ['partial','pending','underemployed'], true)) {
        return '986B00';
    }
    if (in_array($status, ['not started','not submitted','unemployed','missing'], true)) {
        return 'A83232';
    }
    return '000000';
}

function tg_word_page_break()
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

function tg_word_info_table($rowsData)
{
    $rows = [];
    foreach ($rowsData as $row) {
        $rows[] = tg_word_row([
            tg_word_label($row[0], 1700),
            tg_word_value($row[1], 3100, false, tg_word_status_color($row[1])),
            tg_word_label($row[2], 1500),
            tg_word_value($row[3], 3000, false, tg_word_status_color($row[3]))
        ]);
    }

    return tg_word_table($rows, [1700,3100,1500,3000]);
}

function tg_word_answer_tables($answers, &$answerNumber)
{
    if (!$answers) {
        return tg_word_p(
            'No saved survey-answer rows were found for this respondent.',
            false,
            15,
            '777777',
            '',
            0,
            55
        );
    }

    $groups = tg_report_group_answers($answers);
    $body = '';

    foreach ($groups as $category => $categoryAnswers) {
        $rows = [];
        $rows[] = tg_word_row([
            tg_word_cell(
                tg_word_p(strtoupper($category), true, 15, '17365D', '', 0, 0),
                9300,
                'D9EAF7',
                3
            )
        ]);

        $rows[] = tg_word_row([
            tg_word_cell(tg_word_p('No.', true, 14, 'FFFFFF', 'center', 0, 0), 500, '4472C4'),
            tg_word_cell(tg_word_p('Survey Question', true, 14, 'FFFFFF', '', 0, 0), 3900, '4472C4'),
            tg_word_cell(tg_word_p('Submitted Response', true, 14, 'FFFFFF', '', 0, 0), 4900, '4472C4')
        ], true);

        foreach ($categoryAnswers as $answer) {
            $answerNumber++;

            $question = trim((string)($answer['question'] ?? ''));
            if ($question === '') {
                $question = 'Question #' . (int)($answer['question_id'] ?? $answerNumber);
            }

            $rows[] = tg_word_row([
                tg_word_cell(tg_word_p((string)$answerNumber, false, 14, '333333', 'center', 0, 0), 500),
                tg_word_cell(tg_word_p($question, true, 14, '333333', '', 0, 0), 3900),
                tg_word_cell(tg_word_p(tg_report_answer_value($answer), false, 14, '333333', '', 0, 0), 4900)
            ], false, false);
        }

        $body .= tg_word_table($rows, [500,3900,4900]);
        $body .= tg_word_p('', false, 10, 'FFFFFF', '', 0, 20);
    }

    return $body;
}

function tg_word_roster_table($rows, $questionTotal)
{
    $tableRows = [];

    $tableRows[] = tg_word_row([
        tg_word_cell(tg_word_p('No.', true, 12, 'FFFFFF', 'center', 0, 0), 450, '4472C4'),
        tg_word_cell(tg_word_p('Alumni', true, 12, 'FFFFFF', '', 0, 0), 2150, '4472C4'),
        tg_word_cell(tg_word_p('Student ID', true, 12, 'FFFFFF', '', 0, 0), 1300, '4472C4'),
        tg_word_cell(tg_word_p('Program', true, 12, 'FFFFFF', '', 0, 0), 1100, '4472C4'),
        tg_word_cell(tg_word_p('Batch', true, 12, 'FFFFFF', 'center', 0, 0), 850, '4472C4'),
        tg_word_cell(tg_word_p('Survey', true, 12, 'FFFFFF', '', 0, 0), 1250, '4472C4'),
        tg_word_cell(tg_word_p('Employment', true, 12, 'FFFFFF', '', 0, 0), 1350, '4472C4'),
        tg_word_cell(tg_word_p('Progress', true, 12, 'FFFFFF', 'center', 0, 0), 800, '4472C4')
    ], true);

    $number = 0;
    foreach ($rows as $row) {
        $number++;

        $tableRows[] = tg_word_row([
            tg_word_cell(tg_word_p((string)$number, false, 12, '333333', 'center', 0, 0), 450),
            tg_word_cell(tg_word_p($row['lastname'] . ', ' . $row['firstname'], true, 12, '333333', '', 0, 0), 2150),
            tg_word_cell(tg_word_p((string)$row['student_id'], false, 12, '333333', '', 0, 0), 1300),
            tg_word_cell(tg_word_p((string)$row['course_code'], false, 12, '333333', '', 0, 0), 1100),
            tg_word_cell(tg_word_p((string)$row['batch_year'], false, 12, '333333', 'center', 0, 0), 850),
            tg_word_cell(tg_word_p((string)$row['survey_status'], true, 12, tg_word_status_color($row['survey_status']), '', 0, 0), 1250),
            tg_word_cell(tg_word_p((string)$row['employment_status'], false, 12, tg_word_status_color($row['employment_status']), '', 0, 0), 1350),
            tg_word_cell(tg_word_p((int)$row['answers_count'] . '/' . (int)$questionTotal, false, 12, '333333', 'center', 0, 0), 800)
        ], false, true);
    }

    if (!$rows) {
        $tableRows[] = tg_word_row([
            tg_word_cell(
                tg_word_p('No alumni records match the selected report scope.', false, 14, '777777', 'center', 0, 0),
                9250,
                '',
                8
            )
        ]);
    }

    return tg_word_table($tableRows, [450,2150,1300,1100,850,1250,1350,800]);
}

/* ================= BUILD BODY ================= */

$title = strtoupper(tg_report_title($package));
$subtitle = tg_report_subtitle($package);
$college = $package['college'];
$data = $package['data'];
$questionTotal = (int)$package['question_total'];
$surveyVersion = $package['survey_version'];
$surveyVersionLabel = tg_report_version_label($package);
$surveyVersionStatus = $surveyVersion
    ? (string)$surveyVersion['status']
    : 'Legacy';

$body = '';
$body .= tg_word_p($title, true, 22, '17365D', 'center', 80, 30);
$body .= tg_word_p($subtitle, false, 18, '666666', 'center', 0, 70);

$body .= tg_word_info_table([
    ['Report Type', $subtitle, 'Generated', $package['generated_at']],
    ['Department', $college['college_name'] . ' (' . $college['college_code'] . ')', 'Survey Version', $surveyVersionLabel],
    ['Version Status', $surveyVersionStatus, 'Survey Questions', $questionTotal . ' mapped / enabled']
]);

if ($request['template'] === 'individual_survey') {
    $alumni = $data['alumni'];
    $employment = $data['employment'];

    $email = trim((string)($alumni['personal_email'] ?? ''));
    if ($email === '') $email = trim((string)($alumni['student_email'] ?? ''));
    if ($email === '') $email = 'Not provided';

    $mobile = trim((string)($alumni['mobile_number'] ?? ''));
    if ($mobile === '') $mobile = 'Not provided';

    $latinHonor = trim((string)($alumni['latin_honor'] ?? ''));
    if ($latinHonor === '') $latinHonor = 'None';

    $workplace = !empty($alumni['workplace_photo']) ? 'Submitted' : 'Not submitted';
    $companyId = !empty($alumni['company_id_proof']) ? 'Submitted' : 'Not submitted';
    $employmentStatus = $employment && !empty($employment['employment_status'])
        ? (string)$employment['employment_status']
        : 'No employment record';

    $body .= tg_word_section('I. ALUMNI PROFILE');
    $body .= tg_word_info_table([
        ['Student ID', (string)$alumni['student_id'], 'Batch Year', (string)$alumni['batch_year']],
        ['Full Name', tg_report_full_name($alumni), 'Sex', (string)$alumni['sex']],
        ['Program', $alumni['course_code'] . ' - ' . $alumni['course_name'], 'Email', $email],
        ['Mobile Number', $mobile, 'Latin Honor', $latinHonor]
    ]);

    $body .= tg_word_section('II. RESPONSE SUMMARY');
    $body .= tg_word_info_table([
        ['Survey Status', (string)$alumni['survey_status'], 'Completion', (int)$alumni['answers_count'] . ' / ' . $questionTotal . ' (' . (int)$alumni['completion_percent'] . '%)'],
        ['Employment', $employmentStatus, 'Workplace Photo', $workplace],
        ['Company ID', $companyId, 'Program / Batch', $alumni['course_code'] . ' / ' . $alumni['batch_year']]
    ]);

    $body .= tg_word_section('III. SUBMITTED SURVEY RESPONSES');
    $answerNumber = 0;
    $body .= tg_word_answer_tables($data['answers'], $answerNumber);

    if ((int)$request['include_employment'] === 1) {
        $body .= tg_word_section('IV. EMPLOYMENT INFORMATION');

        if (!$employment) {
            $body .= tg_word_p('No employment information is currently available for this alumni record.', false, 15, '777777');
        } else {
            $employmentRows = [
                ['Employment Status', (string)($employment['employment_status'] ?? 'Unknown')]
            ];

            if (!empty($employment['company_name'])) $employmentRows[] = ['Company', (string)$employment['company_name']];
            if (!empty($employment['employer_name'])) $employmentRows[] = ['Employer', (string)$employment['employer_name']];
            if (!empty($employment['position_title'])) $employmentRows[] = ['Position', (string)$employment['position_title']];
            if (!empty($employment['industry'])) $employmentRows[] = ['Industry', (string)$employment['industry']];
            elseif (!empty($employment['employment_sector'])) $employmentRows[] = ['Employment Sector', (string)$employment['employment_sector']];
            if (!empty($employment['work_location'])) $employmentRows[] = ['Work Location', (string)$employment['work_location']];
            if (!empty($employment['job_related_to_course'])) $employmentRows[] = ['Job-Course Alignment', (string)$employment['job_related_to_course']];
            if (isset($employment['monthly_salary']) && $employment['monthly_salary'] !== null && $employment['monthly_salary'] !== '') {
                $employmentRows[] = ['Monthly Salary', 'PHP ' . number_format((float)$employment['monthly_salary'],2)];
            }
            if (!empty($employment['company_address'])) $employmentRows[] = ['Company Address', (string)$employment['company_address']];

            $rows = [];
            foreach ($employmentRows as $item) {
                $rows[] = tg_word_row([
                    tg_word_label($item[0], 2200),
                    tg_word_value($item[1], 7100)
                ]);
            }
            $body .= tg_word_table($rows, [2200,7100]);
        }
    }

} elseif ($request['template'] === 'batch_survey_summary') {
    $s = $data['summary'];

    $body .= tg_word_section('I. BATCH SUMMARY');
    $body .= tg_word_info_table([
        ['Batch Year', (string)$data['batch_year'], 'Total Alumni', (string)$s['total']],
        ['Responded', $s['responded'] . ' (' . $s['response_rate'] . '%)', 'Completed', $s['completed'] . ' (' . $s['completion_rate'] . '%)'],
        ['Partial', (string)$s['partial'], 'Not Started', (string)$s['not_started']],
        ['Employed', (string)$s['employed'], 'Employment Rate', $s['employment_rate'] . '%']
    ]);

    $body .= tg_word_section('II. ALUMNI SURVEY STATUS');
    $body .= tg_word_roster_table($data['rows'], $questionTotal);

    if ((int)$request['include_responses'] === 1) {
        $body .= tg_word_section('III. DETAILED SURVEY RESPONSES');

        foreach ($data['rows'] as $index => $row) {
            if ($index > 0) {
                $body .= tg_word_page_break();
            }

            $body .= tg_word_p(
                $row['lastname'] . ', ' . $row['firstname'] .
                ' - ' . $row['student_id'] .
                ' - ' . $row['course_code'] .
                ' - Batch ' . $row['batch_year'] .
                ' - ' . $row['survey_status'],
                true,
                16,
                '17365D',
                '',
                70,
                50,
                true
            );

            $gid = (int)$row['graduate_id'];
            $answers = $data['answers_by_graduate'][$gid] ?? [];
            $answerNumber = 0;
            $body .= tg_word_answer_tables($answers, $answerNumber);
        }
    }

} else {
    $s = $data['summary'];

    $batchScope = (int)$request['batch_year'] > 0
        ? 'Batch ' . (int)$request['batch_year']
        : 'All Batches';

    $body .= tg_word_section('I. ROSTER SUMMARY');
    $body .= tg_word_info_table([
        ['Records in Report', (string)$s['total'], 'Batch Scope', $batchScope],
        ['Responded', $s['responded'] . ' (' . $s['response_rate'] . '%)', 'Completed', $s['completed'] . ' (' . $s['completion_rate'] . '%)'],
        ['Partial', (string)$s['partial'], 'Not Started', (string)$s['not_started']],
        ['Employed', (string)$s['employed'], 'Employment Rate', $s['employment_rate'] . '%']
    ]);

    $body .= tg_word_section('II. ALUMNI ROSTER + SURVEY STATUS');
    $body .= tg_word_roster_table($data['rows'], $questionTotal);

    if ((int)$request['include_responses'] === 1) {
        $body .= tg_word_section('III. DETAILED SURVEY RESPONSES');

        foreach ($data['rows'] as $index => $row) {
            if ($index > 0) {
                $body .= tg_word_page_break();
            }

            $body .= tg_word_p(
                $row['lastname'] . ', ' . $row['firstname'] .
                ' - ' . $row['student_id'] .
                ' - ' . $row['course_code'] .
                ' - Batch ' . $row['batch_year'] .
                ' - ' . $row['survey_status'],
                true,
                16,
                '17365D',
                '',
                70,
                50,
                true
            );

            $gid = (int)$row['graduate_id'];
            $answers = $data['answers_by_graduate'][$gid] ?? [];
            $answerNumber = 0;
            $body .= tg_word_answer_tables($answers, $answerNumber);
        }
    }
}

$body .= tg_word_p(
    'System-generated by TRACEGRAD. This report contains alumni information intended for authorized institutional use.',
    false,
    14,
    '777777',
    'center',
    100,
    0
);

/* ================= APPLY TEMPLATE ================= */

$tmpBase = tempnam(sys_get_temp_dir(), 'tracegrad_report_');
if ($tmpBase === false) {
    http_response_code(500);
    exit('Unable to create temporary Word report.');
}

$tmpDocx = $tmpBase . '.docx';
@unlink($tmpBase);

if (!copy($templatePath, $tmpDocx)) {
    http_response_code(500);
    exit('Unable to copy the official Word report template.');
}

$zip = new ZipArchive();
if ($zip->open($tmpDocx) !== true) {
    @unlink($tmpDocx);
    http_response_code(500);
    exit('Unable to open the copied Word report template.');
}

$documentXml = $zip->getFromName('word/document.xml');
if ($documentXml === false) {
    $zip->close();
    @unlink($tmpDocx);
    http_response_code(500);
    exit('The Word template is missing word/document.xml.');
}

if (!preg_match('/<w:sectPr>.*?<\/w:sectPr>/s', $documentXml, $sectionMatch)) {
    $zip->close();
    @unlink($tmpDocx);
    http_response_code(500);
    exit('The Word template does not contain a valid section definition.');
}

$sectionProperties = $sectionMatch[0];
$sectionProperties = preg_replace(
    '/<w:pgMar[^>]*\/>/',
    '<w:pgMar w:top="1950" w:right="1000" w:bottom="1450" w:left="1000" w:header="720" w:footer="720" w:gutter="0"/>',
    $sectionProperties
);

$newBody = '<w:body>' . $body . $sectionProperties . '</w:body>';
$updated = preg_replace_callback(
    '/<w:body>.*?<\/w:body>/s',
    function () use ($newBody) {
        return $newBody;
    },
    $documentXml,
    1
);

if ($updated === null || $updated === $documentXml) {
    $zip->close();
    @unlink($tmpDocx);
    http_response_code(500);
    exit('The report body could not be inserted into the Word template.');
}

if (!$zip->addFromString('word/document.xml', $updated)) {
    $zip->close();
    @unlink($tmpDocx);
    http_response_code(500);
    exit('The Word report could not be written.');
}

$zip->close();

if (!is_file($tmpDocx) || filesize($tmpDocx) <= 0) {
    @unlink($tmpDocx);
    http_response_code(500);
    exit('The Word report could not be generated.');
}

$filename = tg_report_filename($package, 'docx');

while (ob_get_level() > 0) {
    @ob_end_clean();
}

@ini_set('zlib.output_compression', 'Off');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpDocx));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($tmpDocx);
@unlink($tmpDocx);
exit;
