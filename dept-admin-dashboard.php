<?php
/**
 * TRACEGRAD - Department Admin Dashboard
 * Thin entry point: bootstrap -> layout -> active view -> scripts.
 */
require_once __DIR__ . '/includes/dept-admin-dashboard/bootstrap.php';
require __DIR__ . '/includes/dept-admin-dashboard/layout/start.php';
require __DIR__ . '/includes/dept-admin-dashboard/view-router.php';
require __DIR__ . '/includes/dept-admin-dashboard/layout/end.php';
