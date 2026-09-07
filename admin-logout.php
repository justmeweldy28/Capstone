<?php

/**
 * TRACEGRAD – Administrator Logout
 * --------------------------------------------------------
 * Terminates the authenticated admin session and redirects
 * back to the admin login page with a confirmation notice.
 */

require_once __DIR__ . '/config.php';


/* ============================================================
   DESTROY SESSION DATA
============================================================ */

$_SESSION = [];


/* ------------------------------------------------------------
   Remove the session cookie itself.
   session_destroy() alone only clears server-side session data;
   the browser will keep sending the old session cookie unless
   we also expire it here.
------------------------------------------------------------ */

if (ini_get('session.use_cookies')) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();


/* ============================================================
   REDIRECT WITH LOGOUT CONFIRMATION
============================================================ */

header('Location: admin-login.php?loggedout=1');
exit;