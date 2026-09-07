<?php
require_once __DIR__ . '/includes/shared/error-handling.php';
/**
 * TRACEGRAD - Alumni Forgot Password with Email Delivery
 * =========================================================
 *
 * Purpose:
 * - Verify Student Number + registered email privately.
 * - Create a secure, single-use password-reset token.
 * - Email the reset link through the same PHPMailer/SMTP
 *   service already used by TRACEGRAD Email Reminders.
 *
 * Security:
 * - CSRF protected.
 * - Generic public response prevents account enumeration.
 * - Reset token is stored only as a SHA-256 hash.
 * - Link expires after 30 minutes.
 * - Previous unused reset links are invalidated.
 * - Disabled accounts cannot be reset.
 * - SMTP credentials stay in Apache environment variables.
 *
 * PHP Compatibility:
 * PHP 7.2+
 * =========================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/services/password-reset-service.php';
require_once __DIR__ . '/includes/shared/services/mail-service.php';


/* =========================================================
   1. AUTH / PAGE STATE
   ========================================================= */

if (!empty($_SESSION['alumni_id'])) {
    header('Location: alumni-dashboard.php');
    exit;
}

$error = '';
$success = '';

$studentId =
    trim(
        (string)(
            isset($_POST['student_id'])
                ? $_POST['student_id']
                : ''
        )
    );

$email =
    trim(
        (string)(
            isset($_POST['email'])
                ? $_POST['email']
                : ''
        )
    );


/* =========================================================
   2. HELPERS
   ========================================================= */

function tgForgotEsc($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tgForgotCleanSpaces($value)
{
    $value =
        str_replace(
            "\xC2\xA0",
            ' ',
            (string)$value
        );

    $value =
        preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

    if ($value === null) {
        return trim((string)$value);
    }

    return trim($value);
}

function tgForgotStudentKey($value)
{
    $value =
        tgForgotCleanSpaces(
            $value
        );

    $value =
        preg_replace(
            '/\s*-\s*/',
            '-',
            $value
        );

    if ($value === null) {
        $value = '';
    }

    return strtolower(
        trim($value)
    );
}

function tgForgotEmailKey($value)
{
    $value =
        str_replace(
            array(
                ' ',
                "\t",
                "\r",
                "\n",
                "\xC2\xA0"
            ),
            '',
            (string)$value
        );

    return strtolower(
        trim($value)
    );
}


/**
 * Find a graduate by Student Number while tolerating harmless
 * whitespace differences from spreadsheet/CSV imports.
 */
function tgForgotFindGraduate(PDO $pdo, $submittedStudentId)
{
    $submittedKey =
        tgForgotStudentKey(
            $submittedStudentId
        );

    if ($submittedKey === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT
            g.graduate_id,
            g.student_id,
            g.firstname,
            g.lastname,
            g.student_email,
            g.personal_email,
            g.account_activation,
            aa.account_id,
            aa.account_status
         FROM graduates g
         LEFT JOIN alumni_accounts aa
            ON aa.graduate_id = g.graduate_id
         WHERE g.student_id = ?
         LIMIT 1"
    );

    $stmt->execute(
        array(
            tgForgotCleanSpaces(
                $submittedStudentId
            )
        )
    );

    $graduate =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if ($graduate) {
        return $graduate;
    }

    /*
     * Compatibility fallback for imported records containing
     * harmless spacing around the official Student Number.
     */
    $stmt = $pdo->query(
        "SELECT
            g.graduate_id,
            g.student_id,
            g.firstname,
            g.lastname,
            g.student_email,
            g.personal_email,
            g.account_activation,
            aa.account_id,
            aa.account_status
         FROM graduates g
         LEFT JOIN alumni_accounts aa
            ON aa.graduate_id = g.graduate_id
         ORDER BY g.graduate_id"
    );

    while (
        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            )
    ) {
        if (
            tgForgotStudentKey(
                $row['student_id']
            ) === $submittedKey
        ) {
            return $row;
        }
    }

    return false;
}


/**
 * Build an absolute reset URL.
 *
 * Local test:
 *   TRACEGRAD_BASE_URL=http://localhost/TRACEGRADS
 *
 * Production:
 *   Change TRACEGRAD_BASE_URL to the real public HTTPS URL.
 */
function tgForgotBaseUrl()
{
    $configured =
        tg_mail_env(
            'TRACEGRAD_BASE_URL',
            ''
        );

    if ($configured !== '') {
        return rtrim(
            $configured,
            '/'
        );
    }

    $https =
        !empty($_SERVER['HTTPS']) &&
        strtolower(
            (string)$_SERVER['HTTPS']
        ) !== 'off';

    $scheme =
        $https
            ? 'https'
            : 'http';

    $host =
        isset($_SERVER['HTTP_HOST'])
            ? trim(
                (string)$_SERVER['HTTP_HOST']
            )
            : 'localhost';

    $scriptName =
        isset($_SERVER['SCRIPT_NAME'])
            ? str_replace(
                '\\',
                '/',
                (string)$_SERVER['SCRIPT_NAME']
            )
            : '';

    $directory =
        rtrim(
            str_replace(
                '\\',
                '/',
                dirname(
                    $scriptName
                )
            ),
            '/'
        );

    if (
        $directory === '.' ||
        $directory === '/'
    ) {
        $directory = '';
    }

    return
        $scheme .
        '://' .
        $host .
        $directory;
}


/* =========================================================
   3. CSRF
   ========================================================= */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] =
        bin2hex(
            random_bytes(32)
        );
}


/* =========================================================
   4. RESET REQUEST
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf']) ||
        empty($_SESSION['csrf']) ||
        !hash_equals(
            (string)$_SESSION['csrf'],
            (string)$_POST['csrf']
        )
    ) {
        $error =
            'Security validation failed. Please refresh the page and try again.';
    }

    elseif (
        $studentId === '' ||
        $email === ''
    ) {
        $error =
            'Please enter your Student Number and registered email address.';
    }

    elseif (
        !filter_var(
            tgForgotEmailKey($email),
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $error =
            'Please enter a valid email address.';
    }

    elseif (
        !empty(
            $_SESSION[
                'forgot_password_last_request'
            ]
        ) &&
        (
            time() -
            (int)$_SESSION[
                'forgot_password_last_request'
            ]
        ) < 30
    ) {
        $error =
            'Please wait a few seconds before requesting another reset link.';
    }

    else {

        $_SESSION[
            'forgot_password_last_request'
        ] =
            time();

        /*
         * Always use the same public message whether the record
         * exists or not. This prevents account enumeration.
         */
        $success =
            'If the information matches an active TRACEGRAD alumni account, a password reset link has been sent to the registered email address.';

        try {

            tgExpireOldPasswordResets(
                $pdo
            );

            $graduate =
                tgForgotFindGraduate(
                    $pdo,
                    $studentId
                );

            $matched = false;
            $deliveryEmail = '';
            $deliveryName = '';

            if (
                $graduate &&
                !empty(
                    $graduate['account_id']
                ) &&
                strtolower(
                    trim(
                        (string)(
                            isset(
                                $graduate[
                                    'account_activation'
                                ]
                            )
                                ? $graduate[
                                    'account_activation'
                                ]
                                : ''
                        )
                    )
                ) === 'activated' &&
                strtolower(
                    trim(
                        (string)(
                            isset(
                                $graduate[
                                    'account_status'
                                ]
                            )
                                ? $graduate[
                                    'account_status'
                                ]
                                : ''
                        )
                    )
                ) !== 'disabled'
            ) {

                $submittedEmail =
                    tgForgotEmailKey(
                        $email
                    );

                $studentEmail =
                    tgForgotEmailKey(
                        isset(
                            $graduate[
                                'student_email'
                            ]
                        )
                            ? $graduate[
                                'student_email'
                            ]
                            : ''
                    );

                $personalEmail =
                    tgForgotEmailKey(
                        isset(
                            $graduate[
                                'personal_email'
                            ]
                        )
                            ? $graduate[
                                'personal_email'
                            ]
                            : ''
                    );

                if (
                    $studentEmail !== '' &&
                    hash_equals(
                        $studentEmail,
                        $submittedEmail
                    )
                ) {
                    $matched = true;
                    $deliveryEmail =
                        (string)$graduate[
                            'student_email'
                        ];
                }

                elseif (
                    $personalEmail !== '' &&
                    hash_equals(
                        $personalEmail,
                        $submittedEmail
                    )
                ) {
                    $matched = true;
                    $deliveryEmail =
                        (string)$graduate[
                            'personal_email'
                        ];
                }

                $deliveryName =
                    trim(
                        (
                            isset(
                                $graduate[
                                    'firstname'
                                ]
                            )
                                ? $graduate[
                                    'firstname'
                                ]
                                : ''
                        )
                        .
                        ' '
                        .
                        (
                            isset(
                                $graduate[
                                    'lastname'
                                ]
                            )
                                ? $graduate[
                                    'lastname'
                                ]
                                : ''
                        )
                    );
            }


            if ($matched) {

                $reset =
                    tgCreatePasswordReset(
                        $pdo,
                        (int)$graduate[
                            'graduate_id'
                        ],
                        30
                    );

                $resetUrl =
                    tgForgotBaseUrl()
                    .
                    '/reset-password.php?token='
                    .
                    rawurlencode(
                        $reset['token']
                    );


                $safeName =
                    htmlspecialchars(
                        $deliveryName !== ''
                            ? $deliveryName
                            : 'TRACEGRAD Alumnus',
                        ENT_QUOTES,
                        'UTF-8'
                    );

                $safeUrl =
                    htmlspecialchars(
                        $resetUrl,
                        ENT_QUOTES,
                        'UTF-8'
                    );


                $subject =
                    'TRACEGRAD Password Reset Request';

                $htmlBody =
                    '<div style="font-family:Arial,sans-serif;background:#f4f7fb;padding:28px;">'
                    .
                    '<div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #dce4ef;border-radius:14px;overflow:hidden;">'
                    .
                    '<div style="background:#0b2240;color:#ffffff;padding:22px 26px;">'
                    .
                    '<div style="font-size:20px;font-weight:700;">TRACEGRAD</div>'
                    .
                    '<div style="font-size:12px;margin-top:4px;color:#d8e4f5;">ISUFST - San Enrique Campus</div>'
                    .
                    '</div>'
                    .
                    '<div style="padding:28px 26px;color:#243247;line-height:1.65;">'
                    .
                    '<p>Hello ' .
                    $safeName .
                    ',</p>'
                    .
                    '<p>We received a request to reset the password for your TRACEGRAD Alumni account.</p>'
                    .
                    '<p style="margin:26px 0;text-align:center;">'
                    .
                    '<a href="' .
                    $safeUrl .
                    '" style="display:inline-block;background:#d6a72c;color:#0b2240;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:9px;">Reset My Password</a>'
                    .
                    '</p>'
                    .
                    '<p>This secure link expires in <strong>30 minutes</strong> and can only be used once.</p>'
                    .
                    '<p>If you did not request a password reset, you may safely ignore this email. Your current password will remain unchanged.</p>'
                    .
                    '<p style="margin-top:26px;font-size:12px;color:#66758b;word-break:break-all;">If the button does not work, copy this link into your browser:<br>' .
                    $safeUrl .
                    '</p>'
                    .
                    '</div>'
                    .
                    '</div>'
                    .
                    '</div>';

                $textBody =
                    "TRACEGRAD Password Reset\n\n"
                    .
                    "Hello "
                    .
                    (
                        $deliveryName !== ''
                            ? $deliveryName
                            : 'TRACEGRAD Alumnus'
                    )
                    .
                    ",\n\n"
                    .
                    "We received a request to reset the password for your TRACEGRAD Alumni account.\n\n"
                    .
                    "Reset your password using this secure link:\n"
                    .
                    $resetUrl
                    .
                    "\n\n"
                    .
                    "This link expires in 30 minutes and can only be used once.\n\n"
                    .
                    "If you did not request this reset, ignore this email.";

                $mailResult =
                    tg_mail_send(
                        $deliveryEmail,
                        $deliveryName,
                        $subject,
                        $htmlBody,
                        $textBody
                    );

                if (!$mailResult['ok']) {

                    /*
                     * Keep technical SMTP details in the server log,
                     * not in the public response.
                     */
                    error_log(
                        'TRACEGRAD forgot password email delivery failed: '
                        .
                        $mailResult[
                            'error'
                        ]
                    );

                    /*
                     * Invalidate the newly-created link when delivery
                     * fails so an undelivered token does not remain live.
                     */
                    tgInvalidateGraduatePasswordResets(
                        $pdo,
                        (int)$graduate[
                            'graduate_id'
                        ]
                    );
                }
            }

        } catch (Throwable $e) {

            error_log(
                'TRACEGRAD forgot password request: '
                .
                $e->getMessage()
            );
        }


        $_SESSION['csrf'] =
            bin2hex(
                random_bytes(32)
            );
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
        Forgot Password – TRACEGRAD
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

        <div class="reset-icon">
            <i class="ti ti-key"></i>
        </div>

        <h1>
            Forgot your password?
        </h1>

        <p class="reset-intro">
            Enter your Student Number and one of the email
            addresses registered in the official alumni roster.
            TRACEGRAD will email you a secure reset link.
        </p>


        <?php if ($error !== ''): ?>

            <div
                class="reset-alert reset-alert-error"
                role="alert"
            >
                <i class="ti ti-alert-circle"></i>

                <span>
                    <?= tgForgotEsc($error) ?>
                </span>
            </div>

        <?php endif; ?>


        <?php if ($success !== ''): ?>

            <div
                class="reset-alert reset-alert-success"
                role="status"
            >
                <i class="ti ti-circle-check"></i>

                <span>
                    <?= tgForgotEsc($success) ?>
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
                value="<?= tgForgotEsc($_SESSION['csrf']) ?>"
            >


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
                        value="<?= tgForgotEsc($studentId) ?>"
                        autocomplete="username"
                        maxlength="30"
                        required
                        autofocus
                    >

                </div>

            </div>


            <div class="form-group">

                <label for="registered-email">
                    Registered Email
                </label>

                <div class="input-wrapper">

                    <span class="input-icon">
                        <i class="ti ti-mail"></i>
                    </span>

                    <input
                        type="email"
                        id="registered-email"
                        name="email"
                        placeholder="Enter your registered email"
                        value="<?= tgForgotEsc($email) ?>"
                        autocomplete="email"
                        maxlength="190"
                        required
                    >

                </div>

            </div>


            <button
                type="submit"
                class="reset-button"
            >
                <i class="ti ti-send"></i>
                Email Reset Link
            </button>

        </form>


        <div class="privacy-note">

            <i class="ti ti-shield-lock"></i>

            <span>
                For privacy, TRACEGRAD does not publicly confirm
                whether the submitted Student Number or email
                belongs to an account.
            </span>

        </div>

    </main>


    <div class="reset-footer">

        <a href="alum-login.php">
            <i class="ti ti-arrow-left"></i>
            Back to Alumni Sign In
        </a>

    </div>

</div>

</body>

</html>
