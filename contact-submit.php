<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD Public Contact Submission
 * PHP 7.2 compatible.
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


function tgContactRedirect($state)
{
    header(
        'Location: index.php?contact='
        .
        rawurlencode($state)
        .
        '#contact'
    );

    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tgContactRedirect('invalid');
}


/* Honeypot */
if (!empty($_POST['website'])) {
    tgContactRedirect('sent');
}


/* CSRF */
$token =
    isset($_POST['_contact_token'])
        ? (string) $_POST['_contact_token']
        : '';

$sessionToken =
    isset($_SESSION['tg_contact_csrf'])
        ? (string) $_SESSION['tg_contact_csrf']
        : '';

if (
    $token === ''
    ||
    $sessionToken === ''
    ||
    !hash_equals($sessionToken, $token)
) {
    tgContactRedirect('invalid');
}


/* Simple per-session rate limit */
$now = time();

$lastSubmit =
    isset($_SESSION['tg_contact_last_submit'])
        ? (int) $_SESSION['tg_contact_last_submit']
        : 0;

if (
    $lastSubmit > 0
    &&
    ($now - $lastSubmit) < 45
) {
    tgContactRedirect('rate');
}


/* Input */
$fullname =
    trim(
        (string) (
            isset($_POST['fullname'])
                ? $_POST['fullname']
                : ''
        )
    );

$email =
    trim(
        (string) (
            isset($_POST['email'])
                ? $_POST['email']
                : ''
        )
    );

$subject =
    trim(
        (string) (
            isset($_POST['subject'])
                ? $_POST['subject']
                : ''
        )
    );

$message =
    trim(
        (string) (
            isset($_POST['message'])
                ? $_POST['message']
                : ''
        )
    );


if (
    $fullname === ''
    ||
    strlen($fullname) > 150
    ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
    ||
    strlen($email) > 190
    ||
    $subject === ''
    ||
    strlen($subject) > 180
    ||
    $message === ''
    ||
    strlen($message) > 3000
) {
    tgContactRedirect('invalid');
}


try {

    $tableCheck =
        $pdo->query(
            "SHOW TABLES LIKE 'contact_messages'"
        );

    if (
        !$tableCheck
        ||
        $tableCheck->fetchColumn() === false
    ) {
        tgContactRedirect('setup');
    }


    $stmt =
        $pdo->prepare(
            "INSERT INTO contact_messages
                (
                    fullname,
                    email,
                    subject,
                    message,
                    status,
                    created_at,
                    updated_at
                )
             VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    'New',
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )"
        );

    $stmt->execute(
        [
            $fullname,
            $email,
            $subject,
            $message
        ]
    );


    $_SESSION['tg_contact_last_submit'] =
        $now;


    try {
        $_SESSION['tg_contact_csrf'] =
            bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['tg_contact_csrf'] =
            hash(
                'sha256',
                session_id() . microtime(true)
            );
    }


    tgContactRedirect('sent');

} catch (Throwable $e) {

    error_log(
        'TRACEGRAD contact form error: '
        .
        $e->getMessage()
    );

    tgContactRedirect('error');
}
