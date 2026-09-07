<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD Unified Alumni / Institutional Report Preview
 * Shared by Super Admin and Department Admin.
 *
 * The Reports tab always opens this page in preview mode first.
 * Export links reuse the exact same filter query.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';

$currentAdmin = tgRequireCurrentAdminSession($pdo, 'admin-login.php');
$roleId = (int)$currentAdmin['role_id'];
if (!in_array($roleId, array(1, 2), true)) {
    http_response_code(403);
    exit('Unauthorized.');
}

$isSuper = $roleId === 1;
$forcedCollegeId = $isSuper ? 0 : (int)($_SESSION['admin_college_id'] ?? 0);
if (!$isSuper && $forcedCollegeId <= 0) {
    http_response_code(403);
    exit('Department assignment is missing.');
}

function tg_rpt_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tg_rpt_in($key, $default = '')
{
    return trim((string)($_GET[$key] ?? $default));
}

function tg_rpt_csv_safe($value)
{
    $text = (string)$value;
    if ($text !== '' && in_array($text[0], array('=', '+', '-', '@'), true)) {
        return "'" . $text;
    }
    return $text;
}

$reportCategories = array(
    'comprehensive' => array('title' => 'Comprehensive Alumni Report', 'subtitle' => 'Important institutional alumni, account, survey, verification, and employment details.'),
    'alumni' => array('title' => 'Alumni Directory Report', 'subtitle' => 'Identity, program, contact, and account-readiness information.'),
    'employment' => array('title' => 'Employment Outcomes Report', 'subtitle' => 'Current employment status, employer, position, job alignment, and general workplace location.'),
    'survey' => array('title' => 'Monitoring Form Participation Report', 'subtitle' => 'Monitoring-form participation and selected survey section/category scope.'),
    'verification' => array('title' => 'Verification & Account Readiness Report', 'subtitle' => 'Account activation, verification status, and monitoring-form readiness.'),
);

$reportCategory = strtolower(tg_rpt_in('report_category', 'comprehensive'));
if (!isset($reportCategories[$reportCategory])) {
    $reportCategory = 'comprehensive';
}

$format = strtolower(tg_rpt_in('format', 'html'));
if (!in_array($format, array('html', 'csv', 'word'), true)) {
    $format = 'html';
}

$collegeId = $forcedCollegeId ?: max(0, (int)tg_rpt_in('college_id', '0'));
$courseId = max(0, (int)tg_rpt_in('course_id', '0'));
$batchYear = max(0, (int)tg_rpt_in('batch_year', '0'));
$employmentStatus = tg_rpt_in('employment_status');
$surveyStatus = tg_rpt_in('survey_status');
$verificationStatus = tg_rpt_in('verification_status');
$accountStatus = tg_rpt_in('account_status');
$sex = tg_rpt_in('sex');
$jobRelated = tg_rpt_in('job_related');
$search = tg_rpt_in('q');
$surveyVersionId = max(0, (int)tg_rpt_in('survey_version_id', '0'));
$surveyCategoryId = max(0, (int)tg_rpt_in('survey_category_id', '0'));

$surveyVersionLabel = '';
if ($surveyVersionId <= 0) {
    try {
        $versionStmt = $pdo->query(
            "SELECT survey_version_id, version_name, title
             FROM survey_versions
             WHERE status='Published'
               AND (start_at IS NULL OR start_at<=NOW())
               AND (end_at IS NULL OR end_at>=NOW())
             ORDER BY published_at DESC, survey_version_id DESC
             LIMIT 1"
        );
        $versionRow = $versionStmt->fetch();
        if ($versionRow) {
            $surveyVersionId = (int)$versionRow['survey_version_id'];
            $surveyVersionLabel = trim((string)$versionRow['version_name'] . ' — ' . (string)$versionRow['title']);
        }
    } catch (Throwable $e) {
        $surveyVersionId = 0;
    }
} else {
    try {
        $versionStmt = $pdo->prepare(
            "SELECT version_name, title
             FROM survey_versions
             WHERE survey_version_id=?
             LIMIT 1"
        );
        $versionStmt->execute(array($surveyVersionId));
        $versionRow = $versionStmt->fetch();
        if ($versionRow) {
            $surveyVersionLabel = trim((string)$versionRow['version_name'] . ' — ' . (string)$versionRow['title']);
        }
    } catch (Throwable $e) {
    }
}

$where = array('1=1');
$params = array();

if ($collegeId > 0) {
    $where[] = 'c.college_id=?';
    $params[] = $collegeId;
}
if ($courseId > 0) {
    $where[] = 'g.course_id=?';
    $params[] = $courseId;
}
if ($batchYear > 0) {
    $where[] = 'g.batch_year=?';
    $params[] = $batchYear;
}
if (in_array($sex, array('Male', 'Female'), true)) {
    $where[] = 'g.sex=?';
    $params[] = $sex;
}
if ($employmentStatus !== '') {
    $where[] = "COALESCE(e.employment_status,'No Record')=?";
    $params[] = $employmentStatus;
}
if ($verificationStatus !== '') {
    $where[] = "COALESCE(av.verification_status,'Pending')=?";
    $params[] = $verificationStatus;
}
if ($accountStatus !== '') {
    if ($accountStatus === 'Not Activated') {
        $where[] = "g.account_activation='Not Activated'";
    } elseif ($accountStatus === 'Activated') {
        $where[] = "g.account_activation='Activated'";
    } else {
        $where[] = 'aa.account_status=?';
        $params[] = $accountStatus;
    }
}
if ($jobRelated !== '') {
    $where[] = "COALESCE(e.job_related_to_course,'No Record')=?";
    $params[] = $jobRelated;
}
if ($surveyStatus !== '') {
    if ($surveyStatus === 'Not Started') {
        $where[] = 'ss.submission_id IS NULL';
    } else {
        $where[] = 'ss.status=?';
        $params[] = $surveyStatus;
    }
}
if ($surveyCategoryId > 0) {
    $surveyCategorySql =
        "EXISTS (
            SELECT 1
            FROM survey_answers sa_filter
            JOIN survey_questions sq_filter ON sq_filter.question_id=sa_filter.question_id ";
    if ($surveyVersionId > 0) {
        $surveyCategorySql .=
            "JOIN survey_version_questions svq_filter
               ON svq_filter.question_id=sq_filter.question_id
              AND svq_filter.survey_version_id=" . (int)$surveyVersionId . "
              AND svq_filter.is_enabled=1 ";
    }
    $surveyCategorySql .=
        "WHERE sa_filter.graduate_id=g.graduate_id
           AND sq_filter.category_id=?
         )";
    $where[] = $surveyCategorySql;
    $params[] = $surveyCategoryId;
}
if ($search !== '') {
    $where[] =
        "(g.student_id LIKE ?
          OR g.lastname LIKE ?
          OR g.firstname LIKE ?
          OR g.personal_email LIKE ?
          OR g.student_email LIKE ?
          OR e.employer_name LIKE ?
          OR e.position_title LIKE ?)";
    $like = '%' . $search . '%';
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
    }
}

$surveyJoin = $surveyVersionId > 0
    ? 'LEFT JOIN survey_submissions ss ON ss.graduate_id=g.graduate_id AND ss.survey_version_id=' . (int)$surveyVersionId
    : 'LEFT JOIN survey_submissions ss ON 1=0';

$sql =
    "SELECT
        g.graduate_id,
        g.student_id,
        g.lastname,
        g.firstname,
        g.middlename,
        g.suffix,
        g.sex,
        g.personal_email,
        g.student_email,
        g.mobile_number,
        g.batch_year,
        g.account_activation,
        c.course_code,
        c.course_name,
        col.college_code,
        col.college_name,
        aa.account_status,
        COALESCE(av.verification_status,'Pending') AS verification_status,
        CASE WHEN ss.submission_id IS NULL THEN 'Not Started' ELSE ss.status END AS survey_status,
        e.employment_status,
        e.employer_name,
        e.position_title,
        e.job_related_to_course,
        e.work_region,
        e.work_city,
        e.work_province,
        e.work_country
     FROM graduates g
     JOIN courses c ON c.course_id=g.course_id
     JOIN colleges col ON col.college_id=c.college_id
     LEFT JOIN alumni_accounts aa ON aa.graduate_id=g.graduate_id
     LEFT JOIN alumni_verifications av ON av.graduate_id=g.graduate_id
     LEFT JOIN employment e
       ON e.employment_id=(
         SELECT e2.employment_id
         FROM employment e2
         WHERE e2.graduate_id=g.graduate_id
         ORDER BY e2.currently_employed DESC, e2.updated_at DESC, e2.employment_id DESC
         LIMIT 1
       )
     $surveyJoin
     WHERE " . implode(' AND ', $where) . "
     ORDER BY col.college_code, c.course_code, g.batch_year DESC, g.lastname, g.firstname";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$scopeFilters = array('Category: ' . $reportCategories[$reportCategory]['title']);

if ($collegeId > 0) {
    $stmt = $pdo->prepare('SELECT college_code,college_name FROM colleges WHERE college_id=?');
    $stmt->execute(array($collegeId));
    if ($row = $stmt->fetch()) {
        $scopeFilters[] = 'Department: ' . $row['college_code'] . ' — ' . $row['college_name'];
    }
}
if ($courseId > 0) {
    $stmt = $pdo->prepare('SELECT course_code,course_name FROM courses WHERE course_id=?');
    $stmt->execute(array($courseId));
    if ($row = $stmt->fetch()) {
        $scopeFilters[] = 'Program: ' . $row['course_code'] . ' — ' . $row['course_name'];
    }
}
if ($batchYear > 0) {
    $scopeFilters[] = 'Batch: ' . $batchYear;
}
foreach (
    array(
        'Employment' => $employmentStatus,
        'Monitoring Form' => $surveyStatus,
        'Verification' => $verificationStatus,
        'Account' => $accountStatus,
        'Sex' => $sex,
        'Job Alignment' => $jobRelated
    ) as $label => $value
) {
    if ($value !== '') {
        $scopeFilters[] = $label . ': ' . $value;
    }
}
if ($surveyVersionLabel !== '') {
    $scopeFilters[] = 'Form Version: ' . $surveyVersionLabel;
}
if ($surveyCategoryId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT category_name FROM survey_categories WHERE category_id=?');
        $stmt->execute(array($surveyCategoryId));
        $categoryName = (string)$stmt->fetchColumn();
        if ($categoryName !== '') {
            $scopeFilters[] = 'Survey Section / Category: ' . $categoryName;
        }
    } catch (Throwable $e) {
    }
}
if ($search !== '') {
    $scopeFilters[] = 'Search: ' . $search;
}
if (count($scopeFilters) === 1) {
    $scopeFilters[] = $isSuper ? 'Institution-wide alumni population' : 'All alumni in assigned department';
}

$total = count($rows);
$employed = 0;
$submitted = 0;
$verified = 0;
$activated = 0;

foreach ($rows as $row) {
    if (in_array($row['employment_status'], array('Employed', 'Self-Employed', 'Freelancer'), true)) {
        $employed++;
    }
    if ($row['survey_status'] === 'Submitted') {
        $submitted++;
    }
    if ($row['verification_status'] === 'Verified') {
        $verified++;
    }
    if ($row['account_activation'] === 'Activated') {
        $activated++;
    }
}

function tg_rpt_record(array $row, $surveyVersionLabel)
{
    $name = trim(
        $row['lastname'] . ', ' .
        $row['firstname'] . ' ' .
        ($row['middlename'] ? substr($row['middlename'], 0, 1) . '.' : '') . ' ' .
        ($row['suffix'] ?? '')
    );

    $email = $row['personal_email'] ?: $row['student_email'];
    $account = $row['account_activation'] === 'Activated'
        ? ('Activated / ' . ($row['account_status'] ?: 'Active'))
        : 'Not Activated';

    $location = implode(
        ', ',
        array_filter(
            array(
                $row['work_city'],
                $row['work_province'],
                $row['work_region'],
                $row['work_country']
            )
        )
    );

    return array(
        'student_id' => $row['student_id'],
        'name' => $name,
        'sex' => $row['sex'],
        'college_code' => $row['college_code'],
        'department' => $row['college_code'] . ' — ' . $row['college_name'],
        'program' => $row['course_code'] . ' — ' . $row['course_name'],
        'batch' => $row['batch_year'],
        'email' => $email,
        'mobile' => $row['mobile_number'],
        'account' => $account,
        'verification' => $row['verification_status'],
        'survey_version' => $surveyVersionLabel !== '' ? $surveyVersionLabel : 'No active version',
        'survey_status' => $row['survey_status'],
        'employment' => $row['employment_status'] ?: 'No Record',
        'employer' => $row['employer_name'],
        'position' => $row['position_title'],
        'alignment' => $row['job_related_to_course'] ?: 'No Record',
        'location' => $location !== '' ? $location : '—',
    );
}

$columnSets = array(
    'comprehensive' => array(
        'student_id' => 'Student ID',
        'name' => 'Alumni Name',
        'department' => 'Department',
        'program' => 'Program',
        'batch' => 'Batch',
        'account' => 'Account',
        'verification' => 'Verification',
        'survey_status' => 'Monitoring Form',
        'employment' => 'Employment',
    ),
    'alumni' => array(
        'student_id' => 'Student ID',
        'name' => 'Alumni Name',
        'sex' => 'Sex',
        'department' => 'Department',
        'program' => 'Program',
        'batch' => 'Batch',
        'email' => 'Email',
        'mobile' => 'Mobile',
        'account' => 'Account',
    ),
    'employment' => array(
        'student_id' => 'Student ID',
        'name' => 'Alumni Name',
        'department' => 'Department',
        'program' => 'Program',
        'batch' => 'Batch',
        'employment' => 'Employment Status',
        'employer' => 'Employer',
        'position' => 'Position',
        'alignment' => 'Job-Course Alignment',
        'location' => 'Work Location',
    ),
    'survey' => array(
        'student_id' => 'Student ID',
        'name' => 'Alumni Name',
        'department' => 'Department',
        'program' => 'Program',
        'batch' => 'Batch',
        'survey_version' => 'Form Version',
        'survey_status' => 'Monitoring Form Status',
        'verification' => 'Verification',
    ),
    'verification' => array(
        'student_id' => 'Student ID',
        'name' => 'Alumni Name',
        'department' => 'Department',
        'program' => 'Program',
        'batch' => 'Batch',
        'account' => 'Account',
        'verification' => 'Verification',
        'survey_status' => 'Monitoring Form',
        'email' => 'Email',
        'mobile' => 'Mobile',
    ),
);

$columns = $columnSets[$reportCategory];
$records = array();
foreach ($rows as $row) {
    $records[] = tg_rpt_record($row, $surveyVersionLabel);
}

function tg_rpt_college_color($code)
{
    $code = strtoupper(trim((string)$code));
    $map = array(
        'CICI' => array('class' => 'college-cici', 'label' => 'CICI', 'color' => 'Pink',  'band' => '#F9A8D4', 'tint' => '#FFF7FB', 'text' => '#831843'),
        'CBMSD' => array('class' => 'college-cbmsd', 'label' => 'CBMSD', 'color' => 'Red',   'band' => '#FCA5A5', 'tint' => '#FFF8F8', 'text' => '#7F1D1D'),
        'COED' => array('class' => 'college-coed', 'label' => 'COED', 'color' => 'Blue',  'band' => '#93C5FD', 'tint' => '#F8FBFF', 'text' => '#1E3A8A'),
        'COAG' => array('class' => 'college-coag', 'label' => 'COAG', 'color' => 'Green', 'band' => '#86EFAC', 'tint' => '#F7FFF9', 'text' => '#14532D'),
    );
    return isset($map[$code]) ? $map[$code] : array('class' => 'college-other', 'label' => $code ?: 'OTHER', 'color' => 'Gray', 'band' => '#E5E7EB', 'tint' => '#FBFCFD', 'text' => '#374151');
}

$filename =
    'TRACEGRAD_' .
    preg_replace('/[^A-Za-z0-9]+/', '_', $reportCategories[$reportCategory]['title']) .
    '_' .
    date('Ymd_His');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($columns));

    foreach ($records as $record) {
        $line = array();
        foreach ($columns as $key => $label) {
            $line[] = tg_rpt_csv_safe($record[$key] ?? '');
        }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
}

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.doc"');
}

$baseQuery = $_GET;
unset($baseQuery['format']);
$query = http_build_query($baseQuery);

$reportTitle = $reportCategories[$reportCategory]['title'];
$reportSubtitle = $reportCategories[$reportCategory]['subtitle'];
$reportCss = @file_get_contents(__DIR__ . '/assets/css/alumni-report.css');
if ($reportCss === false) {
    $reportCss = '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= tg_rpt_h($reportTitle) ?> · TRACEGRAD</title>
  <style><?= $reportCss ?></style>
</head>
<body>
<main class="report-page">
  <header class="report-header">
    <div class="institution">
      ILOILO STATE UNIVERSITY OF FISHERIES SCIENCE AND TECHNOLOGY
      <br><span>San Enrique Campus · TRACEGRAD</span>
    </div>
    <h1><?= tg_rpt_h($reportTitle) ?></h1>
    <p><?= tg_rpt_h($reportSubtitle) ?> · Generated <?= tg_rpt_h(date('F j, Y · g:i A')) ?></p>
  </header>

  <?php if ($format === 'html'): ?>
    <div class="report-toolbar no-print">
      <a href="?<?= tg_rpt_h($query) ?>&format=word">Download Word</a>
      <a href="?<?= tg_rpt_h($query) ?>&format=csv">Download CSV</a>
      <button type="button" onclick="window.print()">Print / Save PDF</button>
      <button type="button" onclick="window.close()">Close Preview</button>
    </div>
  <?php endif; ?>

  <section class="scope">
    <strong>Report Scope & Filters</strong>
    <p><?= tg_rpt_h(implode(' · ', $scopeFilters)) ?></p>
    <?php if ($surveyCategoryId > 0): ?>
      <small>The selected Survey Section / Category filter includes alumni with at least one recorded answer in that section/category.</small>
    <?php endif; ?>
  </section>

  <section class="kpis">
    <article><span>Matched Alumni</span><strong><?= number_format($total) ?></strong></article>
    <article><span>Employed / Self-employed</span><strong><?= number_format($employed) ?></strong><small><?= $total ? number_format($employed * 100 / $total, 1) : '0.0' ?>%</small></article>
    <article><span>Monitoring Submitted</span><strong><?= number_format($submitted) ?></strong><small><?= $total ? number_format($submitted * 100 / $total, 1) : '0.0' ?>%</small></article>
    <article><span>Verified</span><strong><?= number_format($verified) ?></strong><small><?= $total ? number_format($verified * 100 / $total, 1) : '0.0' ?>%</small></article>
    <article><span>Activated Accounts</span><strong><?= number_format($activated) ?></strong><small><?= $total ? number_format($activated * 100 / $total, 1) : '0.0' ?>%</small></article>
  </section>

  <section class="college-color-legend">
    <strong>Department Color Guide</strong>
    <span class="college-cici" style="background:#FCE7F3;color:#9D174D;border-color:#F9A8D4">CICI · Pink</span>
    <span class="college-cbmsd" style="background:#FEE2E2;color:#991B1B;border-color:#FCA5A5">CBMSD · Red</span>
    <span class="college-coed" style="background:#DBEAFE;color:#1E40AF;border-color:#93C5FD">COED · Blue</span>
    <span class="college-coag" style="background:#DCFCE7;color:#166534;border-color:#86EFAC">COAG · Green</span>
  </section>

  <section class="report-table-section">
    <h2><?= tg_rpt_h($reportTitle) ?> Records</h2>
    <p>The visible columns are controlled by the selected report category. The reporting population is controlled by the combined filters shown above.</p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php foreach ($columns as $label): ?>
              <th><?= tg_rpt_h($label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php $lastCollegeCode = null; ?>
          <?php foreach ($records as $record): ?>
            <?php
              $collegeCode = strtoupper(trim((string)($record['college_code'] ?? '')));
              $collegeStyle = tg_rpt_college_color($collegeCode);
              if ($collegeCode !== $lastCollegeCode):
                $lastCollegeCode = $collegeCode;
            ?>
              <tr class="college-group-row <?= tg_rpt_h($collegeStyle['class']) ?>">
                <td colspan="<?= count($columns) ?>" style="background:<?= tg_rpt_h($collegeStyle['band']) ?>;color:<?= tg_rpt_h($collegeStyle['text']) ?>;font-weight:800"><strong><?= tg_rpt_h($record['department'] ?? $collegeStyle['label']) ?></strong></td>
              </tr>
            <?php endif; ?>
            <tr class="college-data-row <?= tg_rpt_h($collegeStyle['class']) ?>">
              <?php foreach ($columns as $key => $label): ?>
                <td style="background:<?= tg_rpt_h($collegeStyle['tint']) ?>"><?= tg_rpt_h(($record[$key] ?? '') !== '' ? $record[$key] : '—') ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>

          <?php if (!$records): ?>
            <tr>
              <td colspan="<?= count($columns) ?>" class="empty">No alumni records match the selected report category and filters.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <footer>
    TRACEGRAD · Web-based Alumni Tracing System · Iloilo State University of Fisheries Science and Technology — San Enrique Campus.
    This report contains administrative alumni information and should be handled according to institutional privacy policy.
  </footer>
</main>
</body>
</html>
