<?php
/**
 * TRACEGRAD - Alumni Dashboard
 * Thin entry point: bootstrap -> layout -> active view -> scripts.
 */
require_once __DIR__ . '/includes/alumni-dashboard/bootstrap.php';
require __DIR__ . '/includes/alumni-dashboard/layout/start.php';
require __DIR__ . '/includes/alumni-dashboard/view-router.php';
require __DIR__ . '/includes/alumni-dashboard/layout/end.php';
