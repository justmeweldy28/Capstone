<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD - Unified Official Report Preview / Print-PDF
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


$request = tg_report_request($_GET);
$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if (!in_array($format, ['html', 'pdf'], true)) {
    $format = 'html';
}

try {
    $package = tg_report_build($pdo, $collegeId, $request);
} catch (Throwable $e) {
    tgErrorLogThrowable($e, 'Department report build failed');
    http_response_code(400);
    exit(htmlspecialchars(tgPublicExceptionMessage($e, 'The requested report could not be prepared.'), ENT_QUOTES, 'UTF-8'));
}

function tg_preview_esc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tg_preview_status_class($value)
{
    $value = strtolower(trim((string)$value));

    if (in_array($value, ['completed','employed','submitted','active','yes'], true)) {
        return 'status-good';
    }
    if (in_array($value, ['partial','pending','underemployed'], true)) {
        return 'status-warning';
    }
    if (in_array($value, ['not started','not submitted','unemployed','missing'], true)) {
        return 'status-danger';
    }
    return '';
}

function tg_preview_data_uri($mime, $bytes)
{
    return $bytes === false || $bytes === null || $bytes === ''
        ? ''
        : 'data:' . $mime . ';base64,' . base64_encode($bytes);
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

if ($templatePath === '' || !class_exists('ZipArchive')) {
    http_response_code(500);
    exit('The official report template or PHP ZIP extension is unavailable.');
}

$zip = new ZipArchive();
if ($zip->open($templatePath) !== true) {
    http_response_code(500);
    exit('Unable to open the official report template.');
}

$leftLogo = tg_preview_data_uri('image/jpeg', $zip->getFromName('word/media/image2.jpeg'));
$rightLogo = tg_preview_data_uri('image/png', $zip->getFromName('word/media/image1.png'));
$footerArtwork = tg_preview_data_uri('image/jpeg', $zip->getFromName('word/media/image3.jpeg'));
$zip->close();

if ($leftLogo === '' || $rightLogo === '' || $footerArtwork === '') {
    http_response_code(500);
    exit('The official report template is missing required institutional artwork.');
}

$title = tg_report_title($package);
$subtitle = tg_report_subtitle($package);
$college = $package['college'];
$data = $package['data'];
$questionTotal = (int)$package['question_total'];
$surveyVersion = $package['survey_version'];
$surveyVersionLabel = tg_report_version_label($package);
$surveyVersionStatus = $surveyVersion
    ? (string)$surveyVersion['status']
    : 'Legacy';

function tg_preview_info_cols()
{
    return '<colgroup><col class="c1"><col class="c2"><col class="c3"><col class="c4"></colgroup>';
}

function tg_preview_answer_tables($answers)
{
    if (!$answers) {
        return '<div class="report-empty">No saved survey-answer rows were found for this respondent.</div>';
    }

    $groups = tg_report_group_answers($answers);
    $number = 0;
    $html = '';

    foreach ($groups as $category => $rows) {
        $html .= '<table class="official-table answer-table">';
        $html .= '<colgroup><col class="no"><col class="question"><col class="response"></colgroup>';
        $html .= '<thead>';
        $html .= '<tr><th colspan="3" class="section-row">' . tg_preview_esc(strtoupper($category)) . '</th></tr>';
        $html .= '<tr><th class="column-head center">No.</th><th class="column-head">Survey Question</th><th class="column-head">Submitted Response</th></tr>';
        $html .= '</thead><tbody>';

        foreach ($rows as $row) {
            $number++;
            $question = trim((string)($row['question'] ?? ''));
            if ($question === '') {
                $question = 'Question #' . (int)($row['question_id'] ?? $number);
            }

            $html .= '<tr>';
            $html .= '<td class="center">' . $number . '</td>';
            $html .= '<td><strong>' . tg_preview_esc($question) . '</strong></td>';
            $html .= '<td>' . nl2br(tg_preview_esc(tg_report_answer_value($row))) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
    }

    return $html;
}

function tg_preview_respondent_detail($row, $answers, $questionTotal)
{
    $name = tg_report_full_name($row);
    $status = (string)$row['survey_status'];

    $html = '<div class="respondent-block">';
    $html .= '<div class="respondent-heading">' .
        tg_preview_esc($name) .
        ' <span>· ' . tg_preview_esc($row['student_id']) .
        ' · ' . tg_preview_esc($row['course_code']) .
        ' · Batch ' . tg_preview_esc($row['batch_year']) .
        ' · ' . tg_preview_esc($status) . '</span></div>';
    $html .= tg_preview_answer_tables($answers);
    $html .= '</div>';

    return $html;
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= tg_preview_esc($title) ?></title>
<link rel="stylesheet" href="assets/css/tracegrad-report-document.css?v=1">
</head>
<body data-auto-print="<?= $format === 'pdf' ? '1' : '0' ?>">

<div class="report-preview-toolbar">
  <div>
    <strong>TRACEGRAD Official Report Preview</strong>
    <span>DOCX, browser preview, and print/PDF use the same official report structure.</span>
  </div>
  <div class="report-preview-actions">
    <a href="report-export.php?<?= tg_preview_esc(http_build_query(array_merge($request, ['format'=>'docx']))) ?>">Download DOCX</a>
    <button type="button" data-report-print>Print / Save PDF</button>
    <button type="button" data-report-close>Close</button>
  </div>
</div>

<main class="report-page">

<header class="official-report-header">
  <img class="logo-left" src="<?= tg_preview_esc($leftLogo) ?>" alt="ISUFST">
  <div class="official-report-header-copy">
    <div class="republic">Republic of the Philippines</div>
    <div class="university">ILOILO STATE UNIVERSITY OF FISHERIES SCIENCE AND TECHNOLOGY</div>
    <div class="office">ADMISSION AND STUDENT RECORDS OFFICE</div>
    <div class="contact">San Enrique, Iloilo | Email: sanenriquecampus@gmail.com</div>
    <div class="website">Website: www.isufst.edu.ph | Contact No: (033) 327-3405</div>
  </div>
  <img class="logo-right" src="<?= tg_preview_esc($rightLogo) ?>" alt="Bagong Pilipinas">
</header>

<footer class="official-report-footer">
  <img src="<?= tg_preview_esc($footerArtwork) ?>" alt="ISUFST institutional footer">
</footer>

<h1 class="report-document-title"><?= tg_preview_esc(strtoupper($title)) ?></h1>
<div class="report-document-subtitle"><?= tg_preview_esc($subtitle) ?></div>

<table class="official-table report-info-table">
  <?= tg_preview_info_cols() ?>
  <tbody>
    <tr>
      <th>Report Type</th>
      <td><?= tg_preview_esc($subtitle) ?></td>
      <th>Generated</th>
      <td><?= tg_preview_esc($package['generated_at']) ?></td>
    </tr>
    <tr>
      <th>Department</th>
      <td><?= tg_preview_esc($college['college_name']) ?> (<?= tg_preview_esc($college['college_code']) ?>)</td>
      <th>Survey Version</th>
      <td><?= tg_preview_esc($surveyVersionLabel) ?></td>
    </tr>
    <tr>
      <th>Version Status</th>
      <td><?= tg_preview_esc($surveyVersionStatus) ?></td>
      <th>Survey Questions</th>
      <td><?= number_format($questionTotal) ?> mapped / enabled question<?= $questionTotal === 1 ? '' : 's' ?></td>
    </tr>
  </tbody>
</table>

<?php if ($request['template'] === 'individual_survey'): ?>
<?php
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
?>
<section class="report-section">
  <h2 class="report-section-title">I. ALUMNI PROFILE</h2>
  <table class="official-table report-info-table">
    <?= tg_preview_info_cols() ?>
    <tbody>
      <tr><th>Student ID</th><td><?= tg_preview_esc($alumni['student_id']) ?></td><th>Batch Year</th><td><?= tg_preview_esc($alumni['batch_year']) ?></td></tr>
      <tr><th>Full Name</th><td><?= tg_preview_esc(tg_report_full_name($alumni)) ?></td><th>Sex</th><td><?= tg_preview_esc($alumni['sex']) ?></td></tr>
      <tr><th>Program</th><td><?= tg_preview_esc($alumni['course_code'] . ' - ' . $alumni['course_name']) ?></td><th>Email</th><td><?= tg_preview_esc($email) ?></td></tr>
      <tr><th>Mobile Number</th><td><?= tg_preview_esc($mobile) ?></td><th>Latin Honor</th><td><?= tg_preview_esc($latinHonor) ?></td></tr>
    </tbody>
  </table>
</section>

<section class="report-section">
  <h2 class="report-section-title">II. RESPONSE SUMMARY</h2>
  <table class="official-table report-info-table">
    <?= tg_preview_info_cols() ?>
    <tbody>
      <tr>
        <th>Survey Status</th><td class="<?= tg_preview_esc(tg_preview_status_class($alumni['survey_status'])) ?>"><?= tg_preview_esc($alumni['survey_status']) ?></td>
        <th>Completion</th><td><?= (int)$alumni['answers_count'] ?> / <?= $questionTotal ?> (<?= (int)$alumni['completion_percent'] ?>%)</td>
      </tr>
      <tr>
        <th>Employment</th><td class="<?= tg_preview_esc(tg_preview_status_class($employmentStatus)) ?>"><?= tg_preview_esc($employmentStatus) ?></td>
        <th>Workplace Photo</th><td class="<?= tg_preview_esc(tg_preview_status_class($workplace)) ?>"><?= tg_preview_esc($workplace) ?></td>
      </tr>
      <tr>
        <th>Company ID</th><td class="<?= tg_preview_esc(tg_preview_status_class($companyId)) ?>"><?= tg_preview_esc($companyId) ?></td>
        <th>Program / Batch</th><td><?= tg_preview_esc($alumni['course_code'] . ' / ' . $alumni['batch_year']) ?></td>
      </tr>
    </tbody>
  </table>
</section>

<section class="report-section">
  <h2 class="report-section-title">III. SUBMITTED SURVEY RESPONSES</h2>
  <?= tg_preview_answer_tables($data['answers']) ?>
</section>

<?php if ((int)$request['include_employment'] === 1): ?>
<section class="report-section">
  <h2 class="report-section-title">IV. EMPLOYMENT INFORMATION</h2>
  <?php if (!$employment): ?>
    <div class="report-empty">No employment information is currently available for this alumni record.</div>
  <?php else: ?>
    <table class="official-table">
      <colgroup><col style="width:28%"><col style="width:72%"></colgroup>
      <tbody>
        <tr><th>Employment Status</th><td><?= tg_preview_esc($employment['employment_status'] ?? 'Unknown') ?></td></tr>
        <?php if (!empty($employment['company_name'])): ?><tr><th>Company</th><td><?= tg_preview_esc($employment['company_name']) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['employer_name'])): ?><tr><th>Employer</th><td><?= tg_preview_esc($employment['employer_name']) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['position_title'])): ?><tr><th>Position</th><td><?= tg_preview_esc($employment['position_title']) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['industry'])): ?><tr><th>Industry</th><td><?= tg_preview_esc($employment['industry']) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['work_location'])): ?><tr><th>Work Location</th><td><?= tg_preview_esc($employment['work_location']) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['job_related_to_course'])): ?><tr><th>Job-Course Alignment</th><td><?= tg_preview_esc($employment['job_related_to_course']) ?></td></tr><?php endif; ?>
        <?php if (isset($employment['monthly_salary']) && $employment['monthly_salary'] !== null && $employment['monthly_salary'] !== ''): ?><tr><th>Monthly Salary</th><td>PHP <?= number_format((float)$employment['monthly_salary'],2) ?></td></tr><?php endif; ?>
        <?php if (!empty($employment['company_address'])): ?><tr><th>Company Address</th><td><?= tg_preview_esc($employment['company_address']) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php elseif ($request['template'] === 'batch_survey_summary'): ?>
<?php $summary = $data['summary']; ?>
<section class="report-section">
  <h2 class="report-section-title">I. BATCH SUMMARY</h2>
  <table class="official-table report-info-table">
    <?= tg_preview_info_cols() ?>
    <tbody>
      <tr><th>Batch Year</th><td><?= (int)$data['batch_year'] ?></td><th>Total Alumni</th><td><?= number_format($summary['total']) ?></td></tr>
      <tr><th>Responded</th><td><?= number_format($summary['responded']) ?> (<?= (int)$summary['response_rate'] ?>%)</td><th>Completed</th><td><?= number_format($summary['completed']) ?> (<?= (int)$summary['completion_rate'] ?>%)</td></tr>
      <tr><th>Partial</th><td><?= number_format($summary['partial']) ?></td><th>Not Started</th><td><?= number_format($summary['not_started']) ?></td></tr>
      <tr><th>Employed</th><td><?= number_format($summary['employed']) ?></td><th>Employment Rate</th><td><?= (int)$summary['employment_rate'] ?>%</td></tr>
    </tbody>
  </table>
</section>

<section class="report-section">
  <h2 class="report-section-title">II. ALUMNI SURVEY STATUS</h2>
  <table class="official-table roster-table">
    <colgroup><col class="no"><col class="alumni"><col class="student"><col class="program"><col class="batch"><col class="survey"><col class="employment"><col class="completion"></colgroup>
    <thead><tr><th class="column-head center">No.</th><th class="column-head">Alumni</th><th class="column-head">Student ID</th><th class="column-head">Program</th><th class="column-head">Batch</th><th class="column-head">Survey</th><th class="column-head">Employment</th><th class="column-head center">Progress</th></tr></thead>
    <tbody>
      <?php if (!$data['rows']): ?>
        <tr><td colspan="8" class="center">No alumni records were found for this batch.</td></tr>
      <?php else: $n=0; foreach ($data['rows'] as $row): $n++; ?>
        <tr>
          <td class="center"><?= $n ?></td>
          <td><strong><?= tg_preview_esc($row['lastname'] . ', ' . $row['firstname']) ?></strong></td>
          <td><?= tg_preview_esc($row['student_id']) ?></td>
          <td><?= tg_preview_esc($row['course_code']) ?></td>
          <td><?= tg_preview_esc($row['batch_year']) ?></td>
          <td class="<?= tg_preview_esc(tg_preview_status_class($row['survey_status'])) ?>"><?= tg_preview_esc($row['survey_status']) ?></td>
          <td><?= tg_preview_esc($row['employment_status']) ?></td>
          <td class="center"><?= (int)$row['answers_count'] ?>/<?= $questionTotal ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<?php if ((int)$request['include_responses'] === 1): ?>
<section class="report-section">
  <h2 class="report-section-title">III. DETAILED SURVEY RESPONSES</h2>
  <?php
  foreach ($data['rows'] as $row) {
      $gid = (int)$row['graduate_id'];
      $answers = $data['answers_by_graduate'][$gid] ?? [];
      echo tg_preview_respondent_detail($row, $answers, $questionTotal);
  }
  ?>
</section>
<?php endif; ?>

<?php else: ?>
<?php $summary = $data['summary']; ?>
<section class="report-section">
  <h2 class="report-section-title">I. ROSTER SUMMARY</h2>
  <table class="official-table report-info-table">
    <?= tg_preview_info_cols() ?>
    <tbody>
      <tr><th>Records in Report</th><td><?= number_format($summary['total']) ?></td><th>Batch Scope</th><td><?= (int)$request['batch_year'] > 0 ? 'Batch '.(int)$request['batch_year'] : 'All Batches' ?></td></tr>
      <tr><th>Responded</th><td><?= number_format($summary['responded']) ?> (<?= (int)$summary['response_rate'] ?>%)</td><th>Completed</th><td><?= number_format($summary['completed']) ?> (<?= (int)$summary['completion_rate'] ?>%)</td></tr>
      <tr><th>Partial</th><td><?= number_format($summary['partial']) ?></td><th>Not Started</th><td><?= number_format($summary['not_started']) ?></td></tr>
      <tr><th>Employed</th><td><?= number_format($summary['employed']) ?></td><th>Employment Rate</th><td><?= (int)$summary['employment_rate'] ?>%</td></tr>
    </tbody>
  </table>
</section>

<section class="report-section">
  <h2 class="report-section-title">II. ALUMNI ROSTER + SURVEY STATUS</h2>
  <table class="official-table roster-table">
    <colgroup><col class="no"><col class="alumni"><col class="student"><col class="program"><col class="batch"><col class="survey"><col class="employment"><col class="completion"></colgroup>
    <thead><tr><th class="column-head center">No.</th><th class="column-head">Alumni</th><th class="column-head">Student ID</th><th class="column-head">Program</th><th class="column-head">Batch</th><th class="column-head">Survey</th><th class="column-head">Employment</th><th class="column-head center">Progress</th></tr></thead>
    <tbody>
      <?php if (!$data['rows']): ?>
        <tr><td colspan="8" class="center">No alumni records match the selected filters.</td></tr>
      <?php else: $n=0; foreach ($data['rows'] as $row): $n++; ?>
        <tr>
          <td class="center"><?= $n ?></td>
          <td><strong><?= tg_preview_esc($row['lastname'] . ', ' . $row['firstname']) ?></strong></td>
          <td><?= tg_preview_esc($row['student_id']) ?></td>
          <td><?= tg_preview_esc($row['course_code']) ?></td>
          <td><?= tg_preview_esc($row['batch_year']) ?></td>
          <td class="<?= tg_preview_esc(tg_preview_status_class($row['survey_status'])) ?>"><?= tg_preview_esc($row['survey_status']) ?></td>
          <td><?= tg_preview_esc($row['employment_status']) ?></td>
          <td class="center"><?= (int)$row['answers_count'] ?>/<?= $questionTotal ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</section>

<?php if ((int)$request['include_responses'] === 1): ?>
<section class="report-section">
  <h2 class="report-section-title">III. DETAILED SURVEY RESPONSES</h2>
  <?php
  foreach ($data['rows'] as $row) {
      $gid = (int)$row['graduate_id'];
      $answers = $data['answers_by_graduate'][$gid] ?? [];
      echo tg_preview_respondent_detail($row, $answers, $questionTotal);
  }
  ?>
</section>
<?php endif; ?>
<?php endif; ?>

<p class="report-document-control">
  System-generated by TRACEGRAD. This report contains alumni information intended for authorized institutional use.
</p>

</main>

<script src="assets/js/tracegrad-report-preview.js?v=1"></script>
</body>
</html>
