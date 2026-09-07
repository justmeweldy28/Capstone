<?php
require_once __DIR__ . '/includes/shared/error-handling.php';

/**
 * TRACEGRAD – Alumni Account Activation
 * PHP 7.2 / XAMPP / MariaDB compatible
 *
 * Verification:
 * - Student Number
 * - Last Name
 * - Registered student or personal email
 * - New password
 */

require_once __DIR__ . '/config.php';

if (!empty($_SESSION['alumni_id'])) {
    header('Location: alumni-dashboard.php');
    exit;
}

$error = '';
$success = '';

/* ------------------------------------------------------------
   Shared helpers
------------------------------------------------------------ */

function esc_activate($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tg_activate_clean_spaces($value)
{
    $value = (string)$value;

    /* Common UTF-8 non-breaking space from CSV/Excel imports. */
    $value = str_replace("\xC2\xA0", ' ', $value);

    $value = preg_replace('/\s+/u', ' ', $value);

    if ($value === null) {
        $value = (string)$value;
    }

    return trim($value);
}

function tg_activate_student_key($value)
{
    $value = tg_activate_clean_spaces($value);

    /*
     * Student IDs sometimes contain accidental spaces around a dash
     * after spreadsheet/CSV import. Normalize only whitespace; do not
     * otherwise rewrite the official identifier.
     */
    $value = preg_replace('/\s*-\s*/', '-', $value);

    if ($value === null) {
        $value = '';
    }

    return strtolower(trim($value));
}

function tg_activate_name_key($value)
{
    return strtolower(tg_activate_clean_spaces($value));
}

function tg_activate_email_key($value)
{
    $value = (string)$value;

    $value = str_replace(
        array(" ", "\t", "\r", "\n", "\xC2\xA0"),
        '',
        $value
    );

    return strtolower(trim($value));
}

function tg_activate_status_key($value)
{
    return strtolower(tg_activate_clean_spaces($value));
}

/*
 * Find a graduate without depending on INFORMATION_SCHEMA and while
 * tolerating harmless whitespace/case differences caused by imports.
 */
function tg_activate_find_graduate(PDO $pdo, $submittedStudentId)
{
    $submittedKey = tg_activate_student_key($submittedStudentId);

    if ($submittedKey === '') {
        return false;
    }

    /*
     * Fast path: normal exact lookup.
     */
    $stmt = $pdo->prepare(
        "SELECT
            g.graduate_id,
            g.student_id,
            g.firstname,
            g.lastname,
            g.middlename,
            g.student_email,
            g.personal_email,
            g.batch_year,
            g.account_activation,
            aa.account_id,
            aa.account_status
         FROM graduates g
         LEFT JOIN alumni_accounts aa
            ON aa.graduate_id = g.graduate_id
         WHERE g.student_id = ?
         LIMIT 1"
    );

    $stmt->execute(array(tg_activate_clean_spaces($submittedStudentId)));
    $graduate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($graduate) {
        return $graduate;
    }

    /*
     * Compatibility fallback for spreadsheet-imported Student IDs.
     * We intentionally keep this bounded to graduate identity fields.
     */
    $stmt = $pdo->query(
        "SELECT
            g.graduate_id,
            g.student_id,
            g.firstname,
            g.lastname,
            g.middlename,
            g.student_email,
            g.personal_email,
            g.batch_year,
            g.account_activation,
            aa.account_id,
            aa.account_status
         FROM graduates g
         LEFT JOIN alumni_accounts aa
            ON aa.graduate_id = g.graduate_id
         ORDER BY g.graduate_id"
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (tg_activate_student_key($row['student_id']) === $submittedKey) {
            return $row;
        }
    }

    return false;
}

function tg_activate_is_activated($graduate)
{
    if (!is_array($graduate)) {
        return false;
    }

    if (!empty($graduate['account_id'])) {
        return true;
    }

    return tg_activate_status_key(
        isset($graduate['account_activation'])
            ? $graduate['account_activation']
            : ''
    ) === 'activated';
}

/* ------------------------------------------------------------
   CSRF
------------------------------------------------------------ */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ------------------------------------------------------------
   Page state / lookup
------------------------------------------------------------ */

$studentId = tg_activate_clean_spaces(
    isset($_GET['student_id'])
        ? $_GET['student_id']
        : (
            isset($_POST['student_id'])
                ? $_POST['student_id']
                : ''
        )
);

$graduate = false;

if ($studentId !== '') {
    try {
        $graduate = tg_activate_find_graduate($pdo, $studentId);

        /*
         * Use the canonical stored Student Number after a successful
         * lookup so the POST request works even if the user originally
         * typed harmless extra spaces.
         */
        if ($graduate && !empty($graduate['student_id'])) {
            $studentId = (string)$graduate['student_id'];
        }
    } catch (Throwable $e) {
        error_log(
            'TRACEGRAD activation lookup error: ' .
            $e->getMessage()
        );

        $error =
            'Unable to verify your student record right now. Please try again later.';
    }
}

/* ------------------------------------------------------------
   POST – Activate account
------------------------------------------------------------ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $error === ''
) {
    $csrf = isset($_POST['csrf'])
        ? (string)$_POST['csrf']
        : '';

    $confirmStudentId = isset($_POST['student_id'])
        ? tg_activate_clean_spaces($_POST['student_id'])
        : '';

    $lastName = isset($_POST['lastname'])
        ? tg_activate_clean_spaces($_POST['lastname'])
        : '';

    $email = isset($_POST['email'])
        ? trim((string)$_POST['email'])
        : '';

    $password = isset($_POST['password'])
        ? (string)$_POST['password']
        : '';

    $confirmPassword = isset($_POST['confirm_password'])
        ? (string)$_POST['confirm_password']
        : '';

    /* CSRF */
    if (
        $csrf === '' ||
        empty($_SESSION['csrf']) ||
        !hash_equals((string)$_SESSION['csrf'], $csrf)
    ) {
        $error =
            'Security validation failed. Refresh the page and try again.';
    }

    /* Student Number */
    elseif (
        $studentId === '' ||
        $confirmStudentId === '' ||
        tg_activate_student_key($studentId) !==
            tg_activate_student_key($confirmStudentId)
    ) {
        $error =
            'Invalid activation request. Please find your graduate record again.';
    }

    /* Graduate existence */
    elseif (!$graduate) {
        $error =
            'Student number not found in the official alumni roster.';
    }

    /* Existing account */
    elseif (tg_activate_is_activated($graduate)) {
        $error =
            'This alumni account is already activated. Please sign in instead.';
    }

    /* Last name */
    elseif (
        $lastName === '' ||
        tg_activate_name_key($lastName) !==
            tg_activate_name_key(
                isset($graduate['lastname'])
                    ? $graduate['lastname']
                    : ''
            )
    ) {
        $error =
            'The last name does not match the official alumni roster.';
    }

    else {
        $registeredEmails = array();

        if (!empty($graduate['student_email'])) {
            $registeredEmails[] =
                tg_activate_email_key($graduate['student_email']);
        }

        if (!empty($graduate['personal_email'])) {
            $registeredEmails[] =
                tg_activate_email_key($graduate['personal_email']);
        }

        $registeredEmails = array_values(
            array_unique(
                array_filter($registeredEmails)
            )
        );

        $submittedEmail = tg_activate_email_key($email);

        if (empty($registeredEmails)) {
            $error =
                'No registered email is stored in this alumni roster record. Please contact the Department Administrator.';
        }

        elseif (
            $submittedEmail === '' ||
            !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)
        ) {
            $error =
                'Please enter a valid registered email address.';
        }

        elseif (
            !in_array(
                $submittedEmail,
                $registeredEmails,
                true
            )
        ) {
            $error =
                'The registered email does not match the official alumni roster.';
        }

        elseif (strlen($password) < 8) {
            $error =
                'Your password must contain at least 8 characters.';
        }

        elseif ($password !== $confirmPassword) {
            $error =
                'Passwords do not match.';
        }

        else {
            try {
                $pdo->beginTransaction();

                /*
                 * Lock by graduate_id obtained from the verified lookup.
                 * This avoids a second fragile Student Number comparison.
                 */
                $check = $pdo->prepare(
                    "SELECT
                        graduate_id,
                        student_id,
                        account_activation
                     FROM graduates
                     WHERE graduate_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $check->execute(
                    array((int)$graduate['graduate_id'])
                );

                $current = $check->fetch(PDO::FETCH_ASSOC);

                if (!$current) {
                    throw new RuntimeException(
                        'Graduate record no longer exists.'
                    );
                }

                $accountCheck = $pdo->prepare(
                    "SELECT
                        account_id,
                        account_status
                     FROM alumni_accounts
                     WHERE graduate_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                $accountCheck->execute(
                    array((int)$current['graduate_id'])
                );

                $existingAccount =
                    $accountCheck->fetch(PDO::FETCH_ASSOC);

                if ($existingAccount) {
                    throw new RuntimeException(
                        'An alumni account already exists for this graduate. Please sign in or use Forgot Password.'
                    );
                }

                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                if ($passwordHash === false) {
                    throw new RuntimeException(
                        'Unable to secure the password.'
                    );
                }

                $insert = $pdo->prepare(
                    "INSERT INTO alumni_accounts
                        (
                            graduate_id,
                            password,
                            account_status,
                            password_changed_at
                        )
                     VALUES
                        (
                            ?,
                            ?,
                            'Active',
                            NOW()
                        )"
                );

                $insert->execute(
                    array(
                        (int)$current['graduate_id'],
                        $passwordHash
                    )
                );

                /*
                 * Do not depend on the old row being exactly
                 * 'Not Activated'. Once the account insert succeeds,
                 * the graduate must be marked Activated.
                 */
                $update = $pdo->prepare(
                    "UPDATE graduates
                     SET account_activation = 'Activated'
                     WHERE graduate_id = ?"
                );

                $update->execute(
                    array((int)$current['graduate_id'])
                );

                /*
                 * Re-read to verify the state rather than depending on
                 * rowCount(), which can vary by driver/configuration.
                 */
                $verify = $pdo->prepare(
                    "SELECT account_activation
                     FROM graduates
                     WHERE graduate_id = ?
                     LIMIT 1"
                );

                $verify->execute(
                    array((int)$current['graduate_id'])
                );

                $newStatus = $verify->fetchColumn();

                if (
                    tg_activate_status_key($newStatus) !==
                    'activated'
                ) {
                    throw new RuntimeException(
                        'Unable to complete account activation.'
                    );
                }

                $pdo->commit();

                $success =
                    'Your alumni account has been activated successfully. You can now sign in using your Student Number and new password.';

                $_POST['password'] = '';
                $_POST['confirm_password'] = '';

                $_SESSION['csrf'] =
                    bin2hex(random_bytes(32));

                /*
                 * Refresh local record for the success UI.
                 */
                $graduate =
                    tg_activate_find_graduate($pdo, $studentId);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'TRACEGRAD alumni activation error: ' .
                    $e->getMessage()
                );

                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    $error =
                        'Unable to activate your account right now. Please try again later.';
                }
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
        Activate Alumni Account – TRACEGRAD
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


    <!-- Alumni Activation CSS -->

    <link
        rel="stylesheet"
        href="assets/css/alum-activate.css"
    >


    <!-- Alumni Activation JavaScript -->

    <script
        src="assets/js/alum-activate.js"
        defer
    ></script>

    <link rel="stylesheet" href="assets/css/auth-final-polish.css">
</head>


<body class="tg-auth-page tg-auth-activate">


<div class="activation-page">


    <div class="activation-container">


        <!-- =================================================
             LEFT PANEL
        ================================================== -->

        <section class="activation-left">


            <!-- TRACEGRAD Logo -->

            <div class="activation-logo">

                <img
                    src="assets/images/tracegrad-logo.png"
                    alt="TRACEGRAD Logo"
                >

            </div>


            <!-- School -->

            <div class="school-label">

                ISUFST · San Enrique Campus

            </div>


            <!-- Title -->

            <h1>

                TRACE<em>GRAD</em>

                <br>

                Account Activation

            </h1>


            <!-- Description -->

            <p class="left-description">

                Activate your verified TRACEGRAD alumni
                portal account using information registered
                in the official ISUFST graduate roster.

            </p>


            <!-- Features -->

            <div class="feature-list">


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-shield-check"></i>

                    </div>

                    <span>

                        Verified against the official alumni roster

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-lock"></i>

                    </div>

                    <span>

                        Secure password-protected alumni account

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-user-graduate"></i>

                    </div>

                    <span>

                        One TRACEGRAD account per graduate

                    </span>

                </div>


                <div class="feature-item">

                    <div class="feature-icon">

                        <i class="ti ti-database-check"></i>

                    </div>

                    <span>

                        Identity details checked against roster data

                    </span>

                </div>


            </div>


            <!-- Footer -->

            <div class="activation-left-footer">

                Account activation is available only
                to verified ISUFST graduate roster members

            </div>


        </section>



        <!-- =================================================
             RIGHT PANEL
        ================================================== -->

        <section class="activation-right">


            <!-- Back -->

            <a
                class="back-link"
                href="alum-login.php"
            >

                <i class="ti ti-arrow-left"></i>

                Back to Alumni Login

            </a>



            <div class="activation-content">


                <!-- Heading -->

                <div class="activation-heading">


                    <span class="activation-heading-icon">

                        <i class="ti ti-user-plus"></i>

                    </span>


                    <div>

                        <h2>

                            Activate Your Account

                        </h2>

                        <p>

                            Set up your secure TRACEGRAD access

                        </p>

                    </div>


                </div>



                <p class="activation-description">

                    Verify your graduate record and create
                    your alumni portal password.

                </p>



                <!-- Error -->

                <?php if ($error !== ''): ?>

                    <div
                        class="activation-alert activation-alert-error"
                        role="alert"
                    >

                        <i class="ti ti-alert-circle"></i>

                        <span>

                            <?= esc_activate(
                                $error
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>



                <!-- Success -->

                <?php if ($success !== ''): ?>

                    <div
                        class="activation-alert activation-alert-success"
                        role="status"
                    >

                        <i class="ti ti-circle-check"></i>

                        <span>

                            <?= esc_activate(
                                $success
                            ) ?>

                        </span>

                    </div>

                <?php endif; ?>



                <!-- =========================================
                     SUCCESS STATE
                ========================================== -->

                <?php if ($success !== ''): ?>


                    <div class="success-panel">

                        <div class="success-icon">

                            <i class="ti ti-circle-check-filled"></i>

                        </div>


                        <h3>

                            Account Activated

                        </h3>


                        <p>

                            Your TRACEGRAD alumni account
                            is ready to use.

                        </p>


                        <a
                            class="primary-button"
                            href="alum-login.php"
                        >

                            <i class="ti ti-login"></i>

                            <span>

                                Go to Alumni Sign In

                            </span>

                        </a>

                    </div>



                <!-- =========================================
                     STUDENT NUMBER LOOKUP
                ========================================== -->

                <?php elseif (!$graduate): ?>


                    <form
                        method="get"
                        autocomplete="off"
                        class="activation-form"
                        id="lookup-form"
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
                                    value="<?= esc_activate(
                                        $studentId
                                    ) ?>"
                                    autocomplete="username"
                                    maxlength="30"
                                    required
                                    autofocus
                                >


                            </div>


                            <p class="field-hint">

                                Enter the Student Number
                                recorded in the official
                                ISUFST graduate roster.

                            </p>

                        </div>


                        <button
                            class="primary-button"
                            id="lookup-button"
                            type="submit"
                        >

                            <i class="ti ti-search"></i>

                            <span>

                                Find My Graduate Record

                            </span>

                        </button>


                    </form>



                <!-- =========================================
                     ALREADY ACTIVATED
                ========================================== -->

                <?php elseif (
                    ($graduate['account_activation'] ?? '') ===
                    'Activated'
                ): ?>


                    <div class="already-activated-panel">


                        <div class="already-icon">

                            <i class="ti ti-shield-check"></i>

                        </div>


                        <h3>

                            Account Already Activated

                        </h3>


                        <p>

                            This Student Number already has
                            an activated TRACEGRAD alumni account.

                        </p>


                        <a
                            class="primary-button"
                            href="alum-login.php"
                        >

                            <i class="ti ti-login"></i>

                            <span>

                                Go to Alumni Sign In

                            </span>

                        </a>


                    </div>



                <!-- =========================================
                     ACTIVATION FORM
                ========================================== -->

                <?php else: ?>


                    <!-- Graduate Summary -->

                    <div class="graduate-summary">


                        <div class="graduate-summary-icon">

                            <i class="ti ti-user-check"></i>

                        </div>


                        <div class="graduate-summary-info">


                            <span class="graduate-label">

                                Graduate record found

                            </span>


                            <strong>

                                <?= esc_activate(
                                    trim(
                                        $graduate['firstname'] .
                                        ' ' .
                                        $graduate['lastname']
                                    )
                                ) ?>

                            </strong>


                            <div class="graduate-meta">

                                <span>

                                    <i class="ti ti-id"></i>

                                    <?= esc_activate(
                                        $graduate['student_id']
                                    ) ?>

                                </span>


                                <span>

                                    <i class="ti ti-calendar"></i>

                                    Batch
                                    <?= esc_activate(
                                        $graduate['batch_year']
                                    ) ?>

                                </span>

                            </div>


                        </div>


                    </div>



                    <form
                        method="post"
                        autocomplete="off"
                        class="activation-form"
                        id="activation-form"
                    >


                        <!-- CSRF -->

                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= esc_activate(
                                $_SESSION['csrf']
                            ) ?>"
                        >


                        <!-- Student ID -->

                        <input
                            type="hidden"
                            name="student_id"
                            value="<?= esc_activate(
                                $studentId
                            ) ?>"
                        >



                        <!-- Last Name -->

                        <div class="form-group">

                            <label for="lastname">

                                Last Name

                            </label>


                            <div class="input-wrapper">


                                <span class="input-icon">

                                    <i class="ti ti-user"></i>

                                </span>


                                <input
                                    type="text"
                                    id="lastname"
                                    name="lastname"
                                    placeholder="Enter your last name"
                                    value="<?= esc_activate(
                                        $_POST['lastname'] ?? ''
                                    ) ?>"
                                    autocomplete="family-name"
                                    maxlength="100"
                                    required
                                >


                            </div>

                        </div>



                        <!-- Email -->

                        <div class="form-group">

                            <label for="email">

                                Registered Email

                            </label>


                            <div class="input-wrapper">


                                <span class="input-icon">

                                    <i class="ti ti-mail"></i>

                                </span>


                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    placeholder="Enter your registered email"
                                    value="<?= esc_activate(
                                        $_POST['email'] ?? ''
                                    ) ?>"
                                    autocomplete="email"
                                    maxlength="150"
                                    required
                                >


                            </div>


                            <p class="field-hint">

                                Use your student or personal email
                                registered in the official alumni roster.

                            </p>

                        </div>



                        <!-- Password -->

                        <div class="form-group">

                            <label for="password">

                                Create Password

                            </label>


                            <div
                                class="input-wrapper password-wrapper"
                            >


                                <span class="input-icon">

                                    <i class="ti ti-lock"></i>

                                </span>


                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Minimum 8 characters"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >


                                <button
                                    class="password-toggle"
                                    type="button"
                                    aria-label="Show password"
                                    data-password-toggle="password"
                                >

                                    <i class="ti ti-eye"></i>

                                </button>


                            </div>

                        </div>



                        <!-- Confirm Password -->

                        <div class="form-group">

                            <label for="confirm-password">

                                Confirm Password

                            </label>


                            <div
                                class="input-wrapper password-wrapper"
                            >


                                <span class="input-icon">

                                    <i class="ti ti-lock-check"></i>

                                </span>


                                <input
                                    type="password"
                                    id="confirm-password"
                                    name="confirm_password"
                                    placeholder="Re-enter your password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >


                                <button
                                    class="password-toggle"
                                    type="button"
                                    aria-label="Show password"
                                    data-password-toggle="confirm-password"
                                >

                                    <i class="ti ti-eye"></i>

                                </button>


                            </div>


                            <p
                                class="password-match-message"
                                id="password-match-message"
                                aria-live="polite"
                            ></p>

                        </div>



                        <!-- Activate -->

                        <button
                            class="primary-button"
                            id="activation-button"
                            type="submit"
                        >

                            <i class="ti ti-user-check"></i>

                            <span>

                                Activate My Account

                            </span>

                        </button>


                    </form>


                <?php endif; ?>



                <!-- Security Note -->

                <div class="security-note">

                    <i class="ti ti-shield-lock"></i>

                    <span>

                        Your activation details are used only
                        to verify your official graduate record.

                    </span>

                </div>


            </div>


        </section>


    </div>


</div>


</body>

</html>