<?php
/**
 * TRACEGRAD - Individual Survey DOCX Compatibility Route
 * ============================================================
 *
 * Historical links still call survey-report-docx.php.
 *
 * To avoid maintaining two different report engines, this file
 * now delegates to the canonical unified report generator:
 *
 *   report-docx.php
 *
 * Supported query values such as graduate_id, survey_version,
 * and include_employment are preserved.
 *
 * PHP 7.2+
 * ============================================================
 */

$_GET['template'] =
    'individual_survey';

$_GET['format'] =
    'docx';

require __DIR__
    . '/report-docx.php';
