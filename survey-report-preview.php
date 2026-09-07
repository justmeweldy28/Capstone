<?php
/**
 * TRACEGRAD - Individual Survey Preview Compatibility Route
 * ============================================================
 *
 * Historical links still call survey-report-preview.php.
 *
 * The canonical report preview engine is report-preview.php, so
 * this wrapper keeps old links working without duplicate survey
 * query/report logic.
 *
 * survey_version, graduate_id, include_employment, and format
 * remain available through the existing query string.
 *
 * PHP 7.2+
 * ============================================================
 */

$_GET['template'] =
    'individual_survey';

if (
    !isset(
        $_GET[
            'format'
        ]
    )
) {
    $_GET['format'] =
        'html';
}

require __DIR__
    . '/report-preview.php';
