<?php
require_once __DIR__ . '/includes/shared/error-handling.php';

/**
 * TRACEGRAD – Administrator Login
 * --------------------------------------------------------
 * Authentication page for Super Admin and
 * College/Department Admin accounts.
 *
 * Phase 1.3 security:
 * - Account-level failed-login limiting.
 * - IP-level credential-spraying limiting.
 * - Existing login_attempts table remains the audit source.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/login-security.php';

/* ============================================================
   ALREADY AUTHENTICATED
============================================================ */

if (!empty($_SESSION['admin_id'])) {

    if (
        isset($_SESSION['role_id']) &&
        (int)$_SESSION['role_id'] === 2
    ) {
        header('Location: dept-admin-dashboard.php');
    } else {
        header('Location: admin-dashboard.php');
    }

    exit;
}

/* ============================================================
   PAGE STATE / CSRF
============================================================ */

$error = '';
$success = '';

if (isset($_GET['loggedout'])) {
    $success = 'You have been signed out successfully.';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ============================================================
   LOGIN REQUEST
============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $ip = tgLoginSafeIp();
    $userAgent = tgLoginSafeUserAgent();

    /* --------------------------------------------------------
       Validate CSRF + Input
    -------------------------------------------------------- */

    if (
        !isset($_POST['csrf']) ||
        !hash_equals(
            (string)$_SESSION['csrf'],
            (string)$_POST['csrf']
        )
    ) {

        $error =
            'Security validation failed. Please refresh the page and try again.';

    } elseif ($username === '' || $password === '') {

        $error =
            'Please enter both username and password.';

    } else {

        /* ----------------------------------------------------
           Phase 1.3 - Check rolling failed-login limits first.
        ---------------------------------------------------- */

        $rateStatus = tgLoginRateStatus(
            $pdo,
            'Admin',
            $username,
            $ip
        );

        if (!empty($rateStatus['blocked'])) {

            tgLoginApplyRetryAfterHeader($rateStatus);
            $error = tgLoginLockMessage($rateStatus);

        } else {

            /* ------------------------------------------------
               Find Active Administrator
            ------------------------------------------------ */

            $stmt = $pdo->prepare(
                "SELECT
                    a.*,
                    r.role_name,
                    a.college_id

                 FROM admins a

                 JOIN roles r
                    ON r.role_id = a.role_id

                 WHERE a.username = ?
                   AND a.status = 'Active'

                 LIMIT 1"
            );

            $stmt->execute(array($username));
            $admin = $stmt->fetch();

            /* ------------------------------------------------
               Verify Password
            ------------------------------------------------ */

            $loginSuccessful =
                $admin &&
                password_verify(
                    $password,
                    $admin['password']
                );

            /* ------------------------------------------------
               Record Login Attempt
            ------------------------------------------------ */

            tgLoginRecordAttempt(
                $pdo,
                'Admin',
                $username,
                $loginSuccessful ? 'Success' : 'Failed',
                $ip,
                $userAgent
            );

            /* ------------------------------------------------
               Successful Login
            ------------------------------------------------ */

            if ($loginSuccessful) {

                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int)$admin['admin_id'];
                $_SESSION['admin_name'] = $admin['fullname'];
                $_SESSION['admin_role'] = $admin['role_name'];
                $_SESSION['role_id'] = (int)$admin['role_id'];
                $_SESSION['admin_college_id'] =
                    (int)($admin['college_id'] ?? 0);

                /* --------------------------------------------
                   Update Last Login
                -------------------------------------------- */

                $updateLastLogin = $pdo->prepare(
                    "UPDATE admins
                     SET last_login = NOW()
                     WHERE admin_id = ?"
                );

                $updateLastLogin->execute(array(
                    $admin['admin_id']
                ));

                /* --------------------------------------------
                   Redirect Based on Role
                -------------------------------------------- */

                if ((int)$admin['role_id'] === 2) {
                    header('Location: dept-admin-dashboard.php');
                } else {
                    header('Location: admin-dashboard.php');
                }

                exit;
            }

            /* ------------------------------------------------
               Invalid Credentials
            ------------------------------------------------ */

            $error = 'Incorrect username or password.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="color-scheme"
        content="dark"
    >

    <title>Administrator Sign In – TRACEGRAD</title>

    <!-- TRACEGRAD Typography -->
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap"
        rel="stylesheet"
    >

    <!-- Tabler Icons -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"
    >

    <!-- Administrator Login CSS -->
    <link
        rel="stylesheet"
        href="assets/css/admin-login.css"
    >
    <link rel="stylesheet" href="assets/css/auth-final-polish.css">
</head>

<body class="tg-auth-page tg-auth-admin">

<div class="login-page">

    <main class="login-container" aria-label="TRACEGRAD Administrator Login">

        <!-- =====================================================
             LEFT BRAND PANEL
        ====================================================== -->
        <section class="login-left">

            <div class="admin-logo">
                <img
                    src="assets/images/tracegrad-logo.png"
                    alt="TRACEGRAD Logo"
                >
            </div>

            <div class="school-label">
                ISUFST · San Enrique Campus
            </div>

            <h1>
                TRACE<em>GRAD</em><br>
                Admin Portal
            </h1>

            <p class="left-description">
                Secure administrative access for Super Administrators
                and College/Department Administrators of Iloilo State
                University of Fisheries Science and Technology – San Enrique Campus.
            </p>

            <div class="feature-list" aria-label="Administrator capabilities">

                <div class="feature-item">
                    <div class="feature-icon" aria-hidden="true">
                        <i class="ti ti-shield-star"></i>
                    </div>
                    <span>Super Admin — system-wide full access</span>
                </div>

                <div class="feature-item">
                    <div class="feature-icon" aria-hidden="true">
                        <i class="ti ti-building"></i>
                    </div>
                    <span>Dept Admin — college-level management</span>
                </div>

                <div class="feature-item">
                    <div class="feature-icon" aria-hidden="true">
                        <i class="ti ti-chart-bar"></i>
                    </div>
                    <span>Employment analytics &amp; CHED reports</span>
                </div>

                <div class="feature-item">
                    <div class="feature-icon" aria-hidden="true">
                        <i class="ti ti-users"></i>
                    </div>
                    <span>Full alumni roster CRUD management</span>
                </div>

            </div>

            <div class="login-left-footer">
                Iloilo State University of Fisheries Science and Technology – San Enrique Campus<br>
                TRACEGRAD v3.0
            </div>

        </section>

        <!-- =====================================================
             RIGHT LOGIN PANEL
        ====================================================== -->
        <section class="login-right">

            <a
                class="back-button"
                href="index.php"
            >
                <i class="ti ti-arrow-left" aria-hidden="true"></i>
                <span>Back to Home</span>
            </a>

            <div class="login-content">

                <div class="login-heading">
                    <span class="login-heading-icon" aria-hidden="true">
                        <i class="ti ti-shield-lock"></i>
                    </span>

                    <div>
                        <h2>Administrator Sign In</h2>
                        <p>Secure TRACEGRAD administrative access</p>
                    </div>
                </div>

                <p class="login-description">
                    Enter the username and password issued to you by the ISUFST Alumni Affairs Office.
                </p>

                <?php if ($error !== ''): ?>
                    <div class="login-alert login-alert-error" role="alert">
                        <i class="ti ti-alert-circle" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="login-alert login-alert-success" role="status">
                        <i class="ti ti-circle-check" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    action=""
                    autocomplete="off"
                    class="login-form"
                    id="admin-login-form"
                >

                    <!-- Required by the existing PHP CSRF validation -->
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <div class="form-group">
                        <label for="admin-username">Username</label>

                        <div class="input-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <i class="ti ti-user"></i>
                            </span>

                            <input
                                type="text"
                                id="admin-username"
                                name="username"
                                placeholder="Enter your username"
                                value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="username"
                                maxlength="100"
                                spellcheck="false"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div class="form-group password-group">
                        <label for="admin-password">Password</label>

                        <div class="input-wrapper password-wrapper">
                            <span class="input-icon" aria-hidden="true">
                                <i class="ti ti-lock"></i>
                            </span>

                            <input
                                type="password"
                                id="admin-password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                class="password-toggle"
                                type="button"
                                id="password-toggle"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                <i class="ti ti-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <button
                        class="login-button"
                        id="admin-login-button"
                        type="submit"
                    >
                        <i class="ti ti-login-2 login-button-icon" aria-hidden="true"></i>
                        <span class="login-button-text">Sign In as Administrator</span>
                    </button>

                </form>

                <div class="security-note">
                    <i class="ti ti-lock-check" aria-hidden="true"></i>
                    <span>This portal is restricted to authorized ISUFST administrators only.</span>
                </div>

            </div>

        </section>

    </main>

</div>

<script>
(function () {
    'use strict';

    const password = document.getElementById('admin-password');
    const toggle = document.getElementById('password-toggle');
    const form = document.getElementById('admin-login-form');
    const button = document.getElementById('admin-login-button');

    if (password && toggle) {
        toggle.addEventListener('click', function () {
            const showing = password.type === 'text';
            password.type = showing ? 'password' : 'text';

            toggle.setAttribute(
                'aria-label',
                showing ? 'Show password' : 'Hide password'
            );

            toggle.setAttribute(
                'aria-pressed',
                showing ? 'false' : 'true'
            );

            const icon = toggle.querySelector('i');
            if (icon) {
                icon.className = showing ? 'ti ti-eye' : 'ti ti-eye-off';
            }
        });
    }

    if (form && button) {
        form.addEventListener('submit', function () {
            if (!form.checkValidity()) {
                return;
            }

            button.disabled = true;
            button.classList.add('is-loading');

            const icon = button.querySelector('.login-button-icon');
            const text = button.querySelector('.login-button-text');

            if (icon) {
                icon.className = 'ti ti-loader-2 login-button-icon login-spinner';
            }

            if (text) {
                text.textContent = 'Signing In...';
            }
        });
    }
})();
</script>

</body>
</html>
