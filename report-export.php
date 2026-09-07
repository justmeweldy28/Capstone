<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD - Report Export Router
 * ------------------------------------------------------------
 * Keeps existing report-export.php links working.
 *
 * format=html -> report-preview.php
 * format=pdf  -> report-preview.php (auto print)
 * format=docx -> report-docx.php
 * format=csv  -> CSV export
 *
 * PHP 7.2+
 */

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));

if ($format === 'html' || $format === 'pdf') {
    require __DIR__ . '/report-preview.php';
    exit;
}

if ($format === 'docx') {
    require __DIR__ . '/report-docx.php';
    exit;
}

if ($format !== 'csv') {
    http_response_code(400);
    exit('Unsupported report format.');
}

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


$request = tg_report_request($_GET);

try {
    $package = tg_report_build($pdo, $collegeId, $request);
} catch (Throwable $e) {
    tgErrorLogThrowable($e, 'Department report export build failed');
    http_response_code(400);
    exit(tgPublicExceptionMessage($e, 'The requested report could not be prepared.'));
}

/**
 * Protect spreadsheet users from CSV / formula injection.
 *
 * Values beginning with =, +, -, or @ are treated as formulas by
 * some spreadsheet applications. Prefixing an apostrophe keeps
 * the exported value as text.
 */
function tg_report_csv_safe($value)
{
    $value = (string)$value;

    if (
        $value !== ''
        &&
        preg_match(
            '/^[=\+\-@]/',
            $value
        )
    ) {
        return "'" . $value;
    }

    return $value;
}


function tg_report_csv_row($handle, $values)
{
    $safe = array();

    foreach ($values as $value) {
        $safe[] =
            tg_report_csv_safe(
                $value
            );
    }

    fputcsv(
        $handle,
        $safe
    );
}


$filename = tg_report_filename($package, 'csv');

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

/* UTF-8 BOM for Excel */
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

if ($request['template'] === 'individual_survey') {
    $alumni = $package['data']['alumni'];

    tg_report_csv_row($out, ['TRACEGRAD INDIVIDUAL SURVEY REPORT']);
    tg_report_csv_row($out, ['Department', $package['college']['college_name']]);
    tg_report_csv_row($out, ['Survey Version', tg_report_version_label($package)]);
    tg_report_csv_row($out, ['Version Status', $package['survey_version'] ? $package['survey_version']['status'] : 'Legacy']);
    tg_report_csv_row($out, ['Survey Version', tg_report_version_label($package)]);
    tg_report_csv_row($out, ['Version Status', $package['survey_version'] ? $package['survey_version']['status'] : 'Legacy']);
    tg_report_csv_row($out, ['Student ID', $alumni['student_id']]);
    tg_report_csv_row($out, ['Name', tg_report_full_name($alumni)]);
    tg_report_csv_row($out, ['Program', $alumni['course_code'] . ' - ' . $alumni['course_name']]);
    tg_report_csv_row($out, ['Batch Year', $alumni['batch_year']]);
    tg_report_csv_row($out, ['Survey Status', $alumni['survey_status']]);
    tg_report_csv_row($out, []);
    tg_report_csv_row($out, ['Category', 'Question', 'Answer']);

    foreach ($package['data']['answers'] as $answer) {
        tg_report_csv_row($out, [
            $answer['category_name'] ?? 'General',
            $answer['question'] ?? '',
            tg_report_answer_value($answer)
        ]);
    }

} elseif ($request['template'] === 'batch_survey_summary') {
    tg_report_csv_row($out, ['TRACEGRAD BATCH SURVEY SUMMARY']);
    tg_report_csv_row($out, ['Department', $package['college']['college_name']]);
    tg_report_csv_row($out, ['Survey Version', tg_report_version_label($package)]);
    tg_report_csv_row($out, ['Version Status', $package['survey_version'] ? $package['survey_version']['status'] : 'Legacy']);
    tg_report_csv_row($out, ['Batch Year', $package['data']['batch_year']]);
    tg_report_csv_row($out, []);
    tg_report_csv_row($out, [
        'Name',
        'Student ID',
        'Program',
        'Batch',
        'Survey Status',
        'Answers',
        'Question Total',
        'Completion %',
        'Employment Status'
    ]);

    foreach ($package['data']['rows'] as $row) {
        tg_report_csv_row($out, [
            $row['lastname'] . ', ' . $row['firstname'],
            $row['student_id'],
            $row['course_code'],
            $row['batch_year'],
            $row['survey_status'],
            $row['answers_count'],
            $package['question_total'],
            $row['completion_percent'],
            $row['employment_status']
        ]);
    }

} else {
    tg_report_csv_row($out, ['TRACEGRAD FULL ROSTER + SURVEY']);
    tg_report_csv_row($out, ['Department', $package['college']['college_name']]);
    tg_report_csv_row($out, [
        'Name',
        'Student ID',
        'Program',
        'Batch',
        'Survey Status',
        'Answers',
        'Question Total',
        'Completion %',
        'Employment Status',
        'Position',
        'Monthly Salary'
    ]);

    foreach ($package['data']['rows'] as $row) {
        tg_report_csv_row($out, [
            $row['lastname'] . ', ' . $row['firstname'],
            $row['student_id'],
            $row['course_code'],
            $row['batch_year'],
            $row['survey_status'],
            $row['answers_count'],
            $package['question_total'],
            $row['completion_percent'],
            $row['employment_status'],
            $row['position_title'] ?? '',
            $row['monthly_salary'] ?? ''
        ]);
    }
}

fclose($out);
exit;
