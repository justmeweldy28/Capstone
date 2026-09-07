<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD – Alumni Login
 * --------------------------------------------------------
 * Authenticates alumni against the official graduate roster.
 *
 * Phase 1.3 security:
 * - Account-level failed-login limiting.
 * - IP-level credential-spraying limiting.
 * - Uses the existing login_attempts table for audit history.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/login-security.php';


/* ============================================================
   ALREADY AUTHENTICATED
============================================================ */

if (!empty($_SESSION['alumni_id'])) {
    header('Location: alumni-dashboard.php');
    exit;
}


/* ============================================================
   PAGE STATE
============================================================ */

$error = '';
$success = '';


/* ============================================================
   CSRF TOKEN
============================================================ */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}


/* ============================================================
   LOGIN REQUEST
============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $password  = (string)($_POST['password'] ?? '');

    $ipAddress = tgLoginSafeIp();
    $userAgent = tgLoginSafeUserAgent();


    /* --------------------------------------------------------
       CSRF validation
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

    }


    /* --------------------------------------------------------
       Required fields
    -------------------------------------------------------- */

    elseif (
        $studentId === '' ||
        $password === ''
    ) {

        $error =
            'Please enter both your Student Number and password.';

    }


    else {

        /* ----------------------------------------------------
           Phase 1.3 - Check rolling failed-login limits first.
        ---------------------------------------------------- */

        $rateStatus = tgLoginRateStatus(
            $pdo,
            'Alumni',
            $studentId,
            $ipAddress
        );

        if (!empty($rateStatus['blocked'])) {

            tgLoginApplyRetryAfterHeader($rateStatus);
            $error = tgLoginLockMessage($rateStatus);

        } else {

            /* ------------------------------------------------
               Find graduate and alumni account
            ------------------------------------------------ */

            $stmt = $pdo->prepare(
                "SELECT
                    g.graduate_id,
                    g.firstname,
                    g.lastname,
                    g.student_id,
                    g.account_activation,
                    g.profile_picture,

                    aa.account_id,
                    aa.password,
                    aa.account_status,
                    aa.last_login

                 FROM graduates g

                 LEFT JOIN alumni_accounts aa
                    ON aa.graduate_id = g.graduate_id

                 WHERE g.student_id = ?

                 LIMIT 1"
            );

            $stmt->execute(array(
                $studentId
            ));

            $alumni = $stmt->fetch();


            /* ------------------------------------------------
               Student number does not exist
            ------------------------------------------------ */

            if (!$alumni) {

                tgLoginRecordAttempt(
                    $pdo,
                    'Alumni',
                    $studentId,
                    'Failed',
                    $ipAddress,
                    $userAgent
                );

                $error =
                    'Student number not found in the alumni roster.';

            }


            /* ------------------------------------------------
               Graduate exists but account is not activated
            ------------------------------------------------ */

            elseif (
                ($alumni['account_activation'] ?? '') ===
                'Not Activated'
            ) {

                header(
                    'Location: alum-activate.php?student_id=' .
                    urlencode($studentId)
                );

                exit;
            }


            /* ------------------------------------------------
               Alumni authentication account is unavailable
            ------------------------------------------------ */

            elseif (
                empty($alumni['account_id']) ||
                empty($alumni['password'])
            ) {

                tgLoginRecordAttempt(
                    $pdo,
                    'Alumni',
                    $studentId,
                    'Failed',
                    $ipAddress,
                    $userAgent
                );

                $error =
                    'Your alumni account is not available. Please contact the Alumni Affairs Office.';

            }


            /* ------------------------------------------------
               Alumni account must be active
            ------------------------------------------------ */

            elseif (
                ($alumni['account_status'] ?? '') !==
                'Active'
            ) {

                tgLoginRecordAttempt(
                    $pdo,
                    'Alumni',
                    $studentId,
                    'Failed',
                    $ipAddress,
                    $userAgent
                );

                $status =
                    trim(
                        (string)($alumni['account_status'] ?? '')
                    );

                $error =
                    'Your account is ' .
                    (
                        $status !== ''
                            ? strtolower($status)
                            : 'unavailable'
                    ) .
                    '. Please contact the Alumni Affairs Office.';

            }


            /* ------------------------------------------------
               Verify password
            ------------------------------------------------ */

            elseif (
                password_verify(
                    $password,
                    $alumni['password']
                )
            ) {

                /* --------------------------------------------
                   Record successful login
                -------------------------------------------- */

                tgLoginRecordAttempt(
                    $pdo,
                    'Alumni',
                    $studentId,
                    'Success',
                    $ipAddress,
                    $userAgent
                );


                /* --------------------------------------------
                   Update last login
                -------------------------------------------- */

                try {

                    $pdo->prepare(
                        "UPDATE alumni_accounts
                         SET last_login = NOW()
                         WHERE account_id = ?"
                    )->execute(array(
                        $alumni['account_id']
                    ));

                } catch (PDOException $e) {

                    /*
                     * Preserve compatibility with an older schema
                     * that may not yet contain last_login.
                     */
                    error_log(
                        'TRACEGRAD alumni last-login update: ' .
                        $e->getMessage()
                    );
                }


                /* --------------------------------------------
                   Secure authenticated session
                -------------------------------------------- */

                session_regenerate_id(true);


                $_SESSION['alumni_id'] =
                    (int)$alumni['graduate_id'];


                $_SESSION['alumni_name'] =
                    trim(
                        $alumni['firstname'] .
                        ' ' .
                        $alumni['lastname']
                    );


                $_SESSION['alumni_pic'] =
                    $alumni['profile_picture']
                    ?? 'default.png';


                /* --------------------------------------------
                   Redirect to Alumni Dashboard
                -------------------------------------------- */

                header(
                    'Location: alumni-dashboard.php'
                );

                exit;
            }


            /* ------------------------------------------------
               Incorrect password
            ------------------------------------------------ */

            else {

                tgLoginRecordAttempt(
                    $pdo,
                    'Alumni',
                    $studentId,
                    'Failed',
                    $ipAddress,
                    $userAgent
                );

                $error =
                    'Incorrect password. Please try again.';
            }
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

    <title>
        Alumni Sign In – TRACEGRAD
    </title>


    <!-- Google Fonts -->

    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,700;1,500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap"
        rel="stylesheet"
    >


    <!-- Tabler Icons -->

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"
    >


    <!-- Alumni Login CSS -->

    <link
        rel="stylesheet"
        href="assets/css/alum-login.css"
    >


    <!-- Alumni Login JavaScript -->

    <script
        src="assets/js/alum-login.js"
        defer
    ></script>



    <link rel="stylesheet" href="assets/css/auth-final-polish.css">
</head>


<body class="tg-auth-page tg-auth-alumni">


<div class="alumni-login-page">


    <div class="alumni-login-container">


        <!-- =================================================
             LEFT PANEL
        ================================================== -->

        <section class="alumni-login-left">


            <!-- TRACEGRAD Logo -->

            <div class="alumni-logo">

                <img
                    src="assets/images/tracegrad-logo.png"
                    alt="TRACEGRAD Logo"
                >

            </div>


            <!-- School -->

            <div class="school-label">

                ISUFST · San Enrique Campus

            </div>


            <!-- Portal Title -->

            <h1>

                TRACE<em>GRAD</em>

                <br>

                Alumni Portal

            </h1>


            <!-- Description -->

            <p class="left-description">

                Access your personal alumni dashboard,
                complete your TRACEGRAD Alumni Monitoring Form,
                manage your employment information,
                and browse the graduation photo gallery.

            </p>


            <!-- Portal Features -->

            <div class="feature-list">


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-clipboard-text"></i>

                    </div>

                    <span>

                        Complete your TRACEGRAD Alumni Monitoring Form

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-briefcase"></i>

                    </div>

                    <span>

                        Manage your employment history

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-photo"></i>

                    </div>

                    <span>

                        Browse and purchase graduation photos

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-user"></i>

                    </div>

                    <span>

                        Update your personal alumni profile

                    </span>

                </div>


            </div>


            <!-- Left Footer -->

            <div class="login-left-footer">

                Access restricted to verified
                ISUFST graduate roster members only

            </div>


        </section>



        <!-- =================================================
             RIGHT PANEL
        ================================================== -->

        <section class="alumni-login-right">


            <!-- Back to Home -->

            <a
                class="back-link"
                href="index.php"
            >

                <i class="ti ti-arrow-left"></i>

                Back to Home

            </a>



            <div class="login-content">


                <!-- Login Heading -->

                <div class="login-heading">


                    <span class="login-heading-icon">

                        <i class="ti ti-user-graduate"></i>

                    </span>


                    <div>

                        <h2>

                            Alumni Sign In

                        </h2>

                        <p>

                            Secure TRACEGRAD alumni access

                        </p>

                    </div>


                </div>


                <p class="login-description">

                    Sign in using your Student Number
                    and alumni portal password.
                    Access is limited to graduates verified
                    against the official batch roster.

                </p>



                <!-- Error Message -->

                <?php if ($error !== ''): ?>

                    <div
                        class="login-alert login-alert-error"
                        role="alert"
                    >

                        <i class="ti ti-alert-circle"></i>

                        <span>

                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>



                <!-- Success Message -->

                <?php if ($success !== ''): ?>

                    <div
                        class="login-alert login-alert-success"
                        role="status"
                    >

                        <i class="ti ti-circle-check"></i>

                        <span>

                            <?= htmlspecialchars(
                                $success,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>



                <!-- Alumni Login Form -->

                <form
                    method="post"
                    autocomplete="off"
                    class="alumni-login-form"
                    id="alumni-login-form"
                >


                    <!-- CSRF -->

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= htmlspecialchars(
                            $_SESSION['csrf'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >



                    <!-- Student Number -->

                    <div class="form-group">

                        <label for="student-id">

                            Student Number

                        </label>


                        <div class="input-wrapper">


                            <span class="input-icon">

                                <i class="ti ti-id"></i>

                            </span>


                            <input
                                type="text"
                                id="student-id"
                                name="student_id"
                                placeholder="e.g. 2024-00142"
                                value="<?= htmlspecialchars(
                                    $_POST['student_id'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                autocomplete="username"
                                maxlength="30"
                                required
                                autofocus
                            >


                        </div>

                    </div>



                    <!-- Password -->

                    <div class="form-group">

                        <label for="alumni-password">

                            Password

                        </label>


                        <div
                            class="input-wrapper password-wrapper"
                        >


                            <span class="input-icon">

                                <i class="ti ti-lock"></i>

                            </span>


                            <input
                                type="password"
                                id="alumni-password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >


                            <button
                                class="password-toggle"
                                type="button"
                                aria-label="Show password"
                                data-password-toggle="alumni-password"
                            >

                                <i class="ti ti-eye"></i>

                            </button>


                        </div>

                    </div>



                    <!-- Login Button -->

                    <button
                        class="login-button"
                        id="alumni-login-button"
                        type="submit"
                    >

                        <i class="ti ti-login"></i>

                        <span>

                            Sign In as Alumni

                        </span>

                    </button>


                </form>



                <!-- =================================================
                     PASSWORD RECOVERY
                     -------------------------------------------------
                     This link is shown only after the complete Forgot
                     Password backend and reset pages are available.
                     ================================================= -->

                <div class="activation-section">

                    <span>
                        Forgot your password?
                    </span>

                    <a href="forgot-password.php">
                        Reset your password
                    </a>

                </div>



                <!-- Activation -->

                <div class="activation-section">

                    <span>

                        Don't have an alumni account yet?

                    </span>

                    <a href="alum-activate.php">

                        Activate your account

                    </a>

                </div>



                <!-- Roster Help -->

                <div class="roster-help">

                    <i class="ti ti-info-circle"></i>

                    <p>

                        Not in the roster?
                        Contact the
                        <strong>
                            ISUFST Office of Alumni Affairs
                        </strong>
                        or your College/Department Admin.

                    </p>

                </div>


                <!-- Security Note -->

                <div class="security-note">

                    <i class="ti ti-shield-check"></i>

                    <span>

                        Alumni access is verified against
                        the official ISUFST graduate roster.

                    </span>

                </div>


            </div>


        </section>


    </div>


</div>


</body>

</html>


