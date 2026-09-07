<?php
/**
 * TRACEGRAD - Super Admin Dashboard
 *
 * Thin entry point only. Business logic, data loading, views, CSS and
 * JavaScript are separated so the dashboard is easier to maintain.
 */

define('TRACEGRAD_ROOT', __DIR__);

require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/bootstrap.php';
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/auth.php';
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/helpers.php';
require_once TRACEGRAD_ROOT . '/includes/authorization-policy.php';
require_once TRACEGRAD_ROOT . '/includes/authorization-enforcer.php';
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/tab-context.php';
tgRequireDefenseUpgradeSchema($pdo);
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/post-handler.php';
require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/data-loader.php';

$analyticsFeatureFocus = trim((string)($_GET['focus'] ?? 'demographics'));
if ($analyticsFeatureFocus === 'alumni') { $analyticsFeatureFocus = 'demographics'; }
// Phase 4.11: retire the older Phase 3.4F analytics page from navigation.
// Preserve old bookmarks by routing focus=survey to the authoritative Phase 4.9 Survey Insights module.
if ($analyticsFeatureFocus === 'survey') { $analyticsFeatureFocus = 'insights'; }
if ($tab === 'analytics' && $analyticsFeatureFocus === 'employment') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/employment-analytics/loader.php';
} elseif ($tab === 'analytics' && $analyticsFeatureFocus === 'programs') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/program-outcomes/loader.php';
} elseif ($tab === 'analytics' && $analyticsFeatureFocus === 'geographic') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/geographic-analytics/loader.php';
} elseif ($tab === 'analytics' && $analyticsFeatureFocus === 'trends') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/trends-analytics/loader.php';
} elseif ($tab === 'analytics' && $analyticsFeatureFocus === 'salary') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/salary-analytics/loader.php';
} elseif ($tab === 'analytics' && $analyticsFeatureFocus === 'insights') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/survey-insights/loader.php';
} elseif ($tab === 'analytics') {
    $analyticsFeatureFocus = 'demographics';
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/demographics-analytics/loader.php';
}

if ($tab === 'survey-management') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/survey-management/loader.php';
}
if ($tab === 'contact-inbox') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/contact-inbox/loader.php';
}
if ($tab === 'system-health') {
    require_once TRACEGRAD_ROOT . '/includes/admin-dashboard/features/system-health/loader.php';
}

require TRACEGRAD_ROOT . '/includes/admin-dashboard/layout/start.php';

$adminViewMap = [
    'dashboard' => 'dashboard.php',
    'admins'    => 'admins.php',
    'roster'    => 'roster.php',
    'departments' => 'departments.php',
    'programs'  => 'programs.php',
    'employers' => 'employers.php',
    'announcements' => 'announcements.php',
    'backup'    => 'backup.php',
    'gallery'   => 'gallery.php',
    'orders'    => 'orders.php',
    'analytics' => 'analytics.php',
    'reports'   => 'reports.php',
    'profile'   => 'profile.php',
    'settings'  => 'settings.php',
    'logs'      => 'logs.php',
];

if ($tab === 'survey-management') {
    require TRACEGRAD_ROOT . '/includes/admin-dashboard/features/survey-management/view.php';
} elseif ($tab === 'contact-inbox') {
    require TRACEGRAD_ROOT . '/includes/admin-dashboard/features/contact-inbox/view.php';
} elseif ($tab === 'system-health') {
    require TRACEGRAD_ROOT . '/includes/admin-dashboard/features/system-health/view.php';
} else {
    require TRACEGRAD_ROOT . '/includes/admin-dashboard/views/' . $adminViewMap[$tab];
}
require TRACEGRAD_ROOT . '/includes/admin-dashboard/layout/end.php';
require TRACEGRAD_ROOT . '/includes/admin-dashboard/components/modals.php';
require TRACEGRAD_ROOT . '/includes/admin-dashboard/scripts.php';
