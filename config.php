<?php
/**
 * TRACEGRAD — Database & Application Configuration
 * =========================================================
 *
 * File:
 * config.php
 *
 * Purpose:
 * - Connect TRACEGRAD to the new trace_alumni database.
 * - Start the shared PHP session.
 * - Load the server-side Groq AI configuration safely.
 *
 * Recommended Runtime:
 * PHP 8.2+ (keep the defense/deployment machine on a supported PHP release)
 * =========================================================
 */


/* =========================================================
   0. APPLICATION TIMEZONE
   ========================================================= */

date_default_timezone_set('Asia/Manila');


/* =========================================================
   1. DATABASE CONFIGURATION
   ========================================================= */

/*
 * These defaults match a typical local XAMPP installation.
 *
 * IMPORTANT:
 * TRACEGRAD now uses the separate working database:
 *
 *     trace_alumni
 *
 * This keeps the older tracegrad_db database untouched.
 */
/*
 * Production credentials should be supplied through server environment
 * variables. The fallback values preserve the normal local XAMPP setup.
 */
if (!function_exists('tgConfigEnv')) {
    function tgConfigEnv($name, $default = '')
    {
        $value = getenv((string)$name);

        if ($value !== false && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        if (isset($_SERVER[$name]) && trim((string)$_SERVER[$name]) !== '') {
            return trim((string)$_SERVER[$name]);
        }

        if (isset($_ENV[$name]) && trim((string)$_ENV[$name]) !== '') {
            return trim((string)$_ENV[$name]);
        }

        return (string)$default;
    }
}

define('DB_HOST', tgConfigEnv('TRACEGRAD_DB_HOST', 'localhost'));
define('DB_NAME', tgConfigEnv('TRACEGRAD_DB_NAME', 'trace_alumni'));
define('DB_USER', tgConfigEnv('TRACEGRAD_DB_USER', 'root'));
define('DB_PASS', tgConfigEnv('TRACEGRAD_DB_PASS', ''));


/* =========================================================
   2. DATABASE CONNECTION
   ========================================================= */

$tgHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$tgHost = preg_replace('/:\d+$/', '', $tgHost);
$tgIsLocal = in_array($tgHost, ['localhost', '127.0.0.1', '::1'], true);

try {

    $pdo = new PDO(
        'mysql:host=' .
        DB_HOST .
        ';dbname=' .
        DB_NAME .
        ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false
        )
    );

} catch (PDOException $e) {

    /*
     * Show a useful local-development message without exposing
     * application credentials.
     */
    die(
        '<div style="
            font-family:Arial,sans-serif;
            max-width:650px;
            margin:80px auto;
            padding:24px;
            border:1px solid #f0c0c0;
            background:#fdf2f2;
            color:#8b1a1a;
            border-radius:10px;
        ">
            <h2 style="margin-top:0">
                Database connection failed
            </h2>

            <p>
                TRACEGRAD could not connect to MySQL.
                Please check the following:
            </p>

            <ul>
                <li>
                    XAMPP Apache and MySQL are running.
                </li>

                <li>
                    The database
                    <code>trace_alumni</code>
                    exists in phpMyAdmin.
                </li>

                <li>
                    <code>trace_alumni.sql</code>
                    was imported successfully.
                </li>

                <li>
                    The DB_HOST, DB_USER and DB_PASS values in
                    <code>config.php</code> match your XAMPP setup.
                </li>
            </ul>

            ' . ($tgIsLocal
                ? '<p style="font-size:13px;color:#666;"><strong>Local development detail:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
                : '<p style="font-size:13px;color:#666;">Technical database details are hidden in deployment mode.</p>') . '
        </div>'
    );
}


/* =========================================================
   2B. DATABASE SCHEMA COMPATIBILITY HELPERS
   ========================================================= */

if (!function_exists('tgDbTableExists')) {
    function tgDbTableExists($pdo, $table)
    {
        static $cache = array();
        $table = (string)$table;
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?"
            );
            $stmt->execute(array($table));
            $cache[$table] = ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('tgDbColumnExists')) {
    function tgDbColumnExists($pdo, $table, $column)
    {
        static $cache = array();
        $key = (string)$table . '.' . (string)$column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?"
            );
            $stmt->execute(array((string)$table, (string)$column));
            $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('tgDefenseUpgradeSchemaMissing')) {
    function tgDefenseUpgradeSchemaMissing($pdo)
    {
        $requirements = array(
            array('gallery_albums', 'college_id'),
            array('colleges', 'gcash_account_name'),
            array('colleges', 'gcash_number'),
            array('colleges', 'gcash_qr'),
            array('survey_categories', 'section_type'),
            array('survey_categories', 'icon_class')
        );

        $missing = array();
        foreach ($requirements as $requirement) {
            if (!tgDbColumnExists($pdo, $requirement[0], $requirement[1])) {
                $missing[] = $requirement[0] . '.' . $requirement[1];
            }
        }

        return $missing;
    }
}

if (!function_exists('tgRequireDefenseUpgradeSchema')) {
    function tgRequireDefenseUpgradeSchema($pdo)
    {
        $missing = tgDefenseUpgradeSchemaMissing($pdo);
        if (!$missing) {
            return;
        }

        http_response_code(503);
        $items = '';
        foreach ($missing as $column) {
            $items .= '<li><code>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</code></li>';
        }

        die(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>TRACEGRAD Database Upgrade Required</title></head>'
            . '<body style="margin:0;background:#f5f7fb;color:#15243a;font-family:Segoe UI,Arial,sans-serif;">'
            . '<main style="max-width:760px;margin:70px auto;padding:0 20px;">'
            . '<section style="background:#fff;border:1px solid #dde5ef;border-radius:16px;padding:28px;box-shadow:0 12px 32px rgba(15,35,55,.08);">'
            . '<h1 style="margin:0 0 12px;font-size:26px;">Database upgrade required</h1>'
            . '<p style="line-height:1.65;">This TRACEGRAD build contains the new Survey Builder and Department Gallery/GCash features, but the current <strong>trace_alumni</strong> database is still using the previous schema.</p>'
            . '<p style="margin-bottom:8px;"><strong>Missing database fields:</strong></p><ul style="line-height:1.8;">' . $items . '</ul>'
            . '<p style="line-height:1.65;">Back up your database, then import <code>database/migrations/2026-08-28-defense-report-survey-commerce-safe.sql</code> in phpMyAdmin. Do not re-import the full database dump over an existing database unless you intentionally want to replace its data.</p>'
            . '<p style="margin-bottom:0;color:#5f6f82;">After the migration finishes successfully, refresh this page.</p>'
            . '</section></main></body></html>'
        );
    }
}


/* =========================================================
   3. PHP SESSION
   ========================================================= */

/*
 * Start one shared session for authentication and dashboard
 * state. Do not start another session when one already exists.
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $tgHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    if ($tgHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_start();
}



