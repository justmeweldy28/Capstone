<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD - Alumni Reset Password
 * =========================================================
 *
 * File:
 * reset-password.php
 *
 * Purpose:
 * Allows an alumnus to create a new password using a valid,
 * unused, unexpired password-reset token.
 *
 * Dependencies:
 * - config.php
 * - includes/shared/services/password-reset-service.php
 *
 * Security:
 * - CSRF protected
 * - Reset token must be valid, unused, and unexpired
 * - Password is stored using password_hash()
 * - Reset token becomes unusable after success
 * - Existing remember-me token is invalidated by the service
 *
 * PHP Compatibility:
 * PHP 7.2+
 * =========================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/services/password-reset-service.php';


/* =========================================================
   1. ALREADY AUTHENTICATED
   ========================================================= */

if (!empty($_SESSION['alumni_id'])) {
    header('Location: alumni-dashboard.php');
    exit;
}


/* =========================================================
   2. PAGE STATE
   ========================================================= */

$error = '';
$success = '';
$resetComplete = false;


/*
 * Accept the token from:
 *
 * GET:
 *   reset-password.php?token=...
 *
 * POST:
 *   hidden token field when the reset form is submitted
 */
$rawToken =
    trim(
        (string)(
            $_POST['token']
            ?? $_GET['token']
            ?? ''
        )
    );


/* =========================================================
   3. CSRF TOKEN
   ========================================================= */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] =
        bin2hex(
            random_bytes(32)
        );
}


/* =========================================================
   4. INITIAL RESET-LINK VALIDATION
   ========================================================= */

$validReset = null;

if ($rawToken !== '') {

    try {

        $validReset =
            tgGetValidPasswordReset(
                $pdo,
                $rawToken
            );

    } catch (Throwable $e) {

        error_log(
            'TRACEGRAD reset-password validation: '
            .
            $e->getMessage()
        );

        $validReset = null;
    }
}


/* =========================================================
   5. PASSWORD RESET SUBMISSION
   ========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reset_password'])
) {

    $newPassword =
        (string)(
            $_POST['new_password']
            ?? ''
        );

    $confirmPassword =
        (string)(
            $_POST['confirm_password']
            ?? ''
        );


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
       Reset token validation
       -------------------------------------------------------- */

    elseif (!$validReset) {

        $error =
            'This password reset link is invalid, expired, or has already been used.';

    }


    /* --------------------------------------------------------
       Required fields
       -------------------------------------------------------- */

    elseif (
        $newPassword === '' ||
        $confirmPassword === ''
    ) {

        $error =
            'Please enter and confirm your new password.';

    }


    /* --------------------------------------------------------
       Password length
       -------------------------------------------------------- */

    elseif (strlen($newPassword) < 8) {

        $error =
            'Your new password must contain at least 8 characters.';

    }


    elseif (strlen($newPassword) > 128) {

        $error =
            'Your new password cannot exceed 128 characters.';

    }


    /* --------------------------------------------------------
       Password confirmation
       -------------------------------------------------------- */

    elseif (
        $newPassword !==
        $confirmPassword
    ) {

        $error =
            'The new passwords do not match.';

    }


    else {

        try {

            /*
             * The shared service performs the final token check
             * again inside a transaction before changing the
             * account password.
             */
            tgResetAlumniPasswordByToken(
                $pdo,
                $rawToken,
                $newPassword
            );


            $resetComplete = true;

            $success =
                'Your TRACEGRAD password has been changed successfully. You can now sign in using your new password.';


            /*
             * The request throttle used by forgot-password.php
             * is no longer needed after a successful reset.
             */
            unset(
                $_SESSION[
                    'forgot_password_last_request'
                ]
            );


            /*
             * Rotate CSRF after the successful state change.
             */
            $_SESSION['csrf'] =
                bin2hex(
                    random_bytes(32)
                );


            /*
             * Do not keep exposing the raw token in rendered form
             * after it has already been consumed.
             */
            $validReset = null;


        } catch (InvalidArgumentException $e) {

            $error =
                $e->getMessage();

        } catch (RuntimeException $e) {

            /*
             * These are safe user-facing reset state messages from
             * the shared service, such as expired/used links.
             */
            $error =
                $e->getMessage();

        } catch (Throwable $e) {

            error_log(
                'TRACEGRAD reset-password submission: '
                .
                $e->getMessage()
            );

            $error =
                'TRACEGRAD could not change your password right now. Please request a new reset link and try again.';
        }
    }
}


/* =========================================================
   6. OUTPUT ESCAPE HELPER
   ========================================================= */

/**
 * Escape a value for safe HTML output.
 *
 * @param mixed $value
 * @return string
 */
function tgResetEsc($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
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
        Reset Password – TRACEGRAD
    </title>

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css"
    >

    <link rel="stylesheet" href="assets/css/password-recovery.css">
    <link rel="stylesheet" href="assets/css/auth-final-polish.css">

</head>

<body class="tg-auth-recovery">

<div class="reset-shell">

    <div class="reset-brand">

        <img
            src="assets/images/tracegrad-logo.png"
            alt="TRACEGRAD Logo"
        >

        <div>
            <strong>
                TRACEGRAD
            </strong>

            <span>
                Alumni Account Recovery
            </span>
        </div>

    </div>


    <main class="reset-card">

        <?php if ($resetComplete): ?>

            <!-- =================================================
                 SUCCESS STATE
                 ================================================= -->

            <div class="reset-icon success">
                <i class="ti ti-shield-check"></i>
            </div>

            <h1>
                Password changed
            </h1>

            <p class="reset-intro">
                Your Alumni account has been secured with your
                new password.
            </p>


            <div
                class="reset-alert reset-alert-success"
                role="status"
            >
                <i class="ti ti-circle-check"></i>

                <span>
                    <?= tgResetEsc($success) ?>
                </span>
            </div>


            <a
                href="alum-login.php"
                class="login-button"
            >
                <i class="ti ti-login"></i>
                Continue to Alumni Sign In
            </a>


        <?php elseif (!$validReset): ?>

            <!-- =================================================
                 INVALID / EXPIRED LINK STATE
                 ================================================= -->

            <div class="reset-icon invalid">
                <i class="ti ti-link-off"></i>
            </div>

            <h1>
                Reset link unavailable
            </h1>

            <p class="reset-intro">
                This password reset link is invalid, expired,
                or has already been used.
            </p>


            <?php if ($error !== ''): ?>

                <div
                    class="reset-alert reset-alert-error"
                    role="alert"
                >
                    <i class="ti ti-alert-circle"></i>

                    <span>
                        <?= tgResetEsc($error) ?>
                    </span>
                </div>

            <?php endif; ?>


            <div class="invalid-box">
                For your account security, reset links are
                single-use and expire automatically. Request a
                new link to continue.
            </div>


            <a
                href="forgot-password.php"
                class="login-button"
            >
                <i class="ti ti-refresh"></i>
                Request a New Reset Link
            </a>


        <?php else: ?>

            <!-- =================================================
                 VALID RESET FORM
                 ================================================= -->

            <div class="reset-icon">
                <i class="ti ti-lock-password"></i>
            </div>

            <h1>
                Create a new password
            </h1>

            <p class="reset-intro">
                Enter a new password for your TRACEGRAD Alumni
                account.
            </p>


            <?php if ($error !== ''): ?>

                <div
                    class="reset-alert reset-alert-error"
                    role="alert"
                >
                    <i class="ti ti-alert-circle"></i>

                    <span>
                        <?= tgResetEsc($error) ?>
                    </span>
                </div>

            <?php endif; ?>


            <form
                method="post"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= tgResetEsc($_SESSION['csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="token"
                    value="<?= tgResetEsc($rawToken) ?>"
                >

                <input
                    type="hidden"
                    name="reset_password"
                    value="1"
                >


                <div class="form-group">

                    <label for="new-password">
                        New Password
                    </label>

                    <div class="input-wrapper">

                        <span class="input-icon">
                            <i class="ti ti-lock"></i>
                        </span>

                        <input
                            type="password"
                            id="new-password"
                            name="new_password"
                            placeholder="Enter your new password"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="128"
                            required
                            autofocus
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-toggle-password="new-password"
                            aria-label="Show new password"
                        >
                            <i class="ti ti-eye"></i>
                        </button>

                    </div>

                </div>


                <div class="form-group">

                    <label for="confirm-password">
                        Confirm New Password
                    </label>

                    <div class="input-wrapper">

                        <span class="input-icon">
                            <i class="ti ti-lock-check"></i>
                        </span>

                        <input
                            type="password"
                            id="confirm-password"
                            name="confirm_password"
                            placeholder="Re-enter your new password"
                            autocomplete="new-password"
                            minlength="8"
                            maxlength="128"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-toggle-password="confirm-password"
                            aria-label="Show confirmed password"
                        >
                            <i class="ti ti-eye"></i>
                        </button>

                    </div>

                </div>


                <div class="password-rules">

                    <i class="ti ti-info-circle"></i>

                    <span>
                        Use at least 8 characters. For better
                        security, combine letters, numbers, and
                        symbols and avoid reusing an old password.
                    </span>

                </div>


                <button
                    type="submit"
                    class="reset-button"
                >
                    <i class="ti ti-device-floppy"></i>
                    Save New Password
                </button>

            </form>

        <?php endif; ?>

    </main>


    <div class="reset-footer">

        <a href="alum-login.php">
            <i class="ti ti-arrow-left"></i>
            Alumni Sign In
        </a>

        <?php if (!$resetComplete): ?>

            <a href="forgot-password.php">
                <i class="ti ti-key"></i>
                Forgot Password
            </a>

        <?php endif; ?>

    </div>

</div>


<script src="assets/js/password-recovery.js" defer></script>

</body>

</html>
