<?php
/**
 * TRACEGRAD - SMTP Configuration Checker
 * Localhost development utility.
 *
 * Important:
 * This page NEVER displays the SMTP password.
 */

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

/*
 * Remove the port from localhost:80 / localhost:8080.
 */
$host = preg_replace('/:\d+$/', '', $host);


/*
 * Keep this utility localhost-only.
 */
if (
    !in_array(
        $host,
        [
            'localhost',
            '127.0.0.1',
            '::1'
        ],
        true
    )
) {
    http_response_code(403);

    exit(
        'This configuration checker is available on localhost only.'
    );
}


/*
 * Helper for reading Apache / PHP environment settings.
 */
function tgMailEnv($name)
{
    $value = getenv($name);

    if (
        $value !== false
        &&
        trim((string)$value) !== ''
    ) {
        return trim((string)$value);
    }


    if (
        isset($_SERVER[$name])
        &&
        trim((string)$_SERVER[$name]) !== ''
    ) {
        return trim((string)$_SERVER[$name]);
    }


    if (
        isset($_ENV[$name])
        &&
        trim((string)$_ENV[$name]) !== ''
    ) {
        return trim((string)$_ENV[$name]);
    }


    return '';
}


$smtpHost =
    tgMailEnv(
        'TRACEGRAD_SMTP_HOST'
    );


$smtpPort =
    tgMailEnv(
        'TRACEGRAD_SMTP_PORT'
    );


$smtpUsername =
    tgMailEnv(
        'TRACEGRAD_SMTP_USERNAME'
    );


$smtpPassword =
    tgMailEnv(
        'TRACEGRAD_SMTP_PASSWORD'
    );


$mailFrom =
    tgMailEnv(
        'TRACEGRAD_MAIL_FROM'
    );


$mailFromName =
    tgMailEnv(
        'TRACEGRAD_MAIL_FROM_NAME'
    );


$curlReady =
    function_exists(
        'curl_init'
    );


$configReady =
    $smtpHost !== ''
    &&
    $smtpPort !== ''
    &&
    $smtpUsername !== ''
    &&
    $smtpPassword !== ''
    &&
    $mailFrom !== ''
    &&
    $mailFromName !== '';

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
        TRACEGRAD Email Configuration Check
    </title>

    <style>

        body {
            margin: 0;
            padding: 40px 20px;

            font-family:
                Arial,
                sans-serif;

            background:
                #eef3f8;

            color:
                #173652;
        }


        .card {
            max-width: 760px;

            margin:
                0 auto;

            padding:
                30px;

            border-radius:
                18px;

            background:
                #ffffff;

            box-shadow:
                0 18px 45px
                rgba(
                    10,
                    35,
                    70,
                    0.10
                );
        }


        h1 {
            margin-top:
                0;

            color:
                #082d5d;
        }


        .subtitle {
            color:
                #66778a;

            line-height:
                1.6;
        }


        .row {
            display:
                flex;

            justify-content:
                space-between;

            gap:
                20px;

            padding:
                14px 0;

            border-bottom:
                1px solid
                #e7edf2;
        }


        .label {
            color:
                #53697d;

            font-weight:
                600;
        }


        .ok {
            color:
                #16834d;

            font-weight:
                700;
        }


        .bad {
            color:
                #b44843;

            font-weight:
                700;
        }


        .note {
            margin-top:
                24px;

            padding:
                14px;

            border-radius:
                10px;

            background:
                #f5f8fb;

            color:
                #607084;

            line-height:
                1.6;
        }

    </style>

</head>

<body>

    <main class="card">

        <h1>
            TRACEGRAD Email Configuration Check
        </h1>

        <p class="subtitle">
            This page checks whether Apache/PHP can read
            your SMTP configuration. Your SMTP password
            is intentionally never displayed.
        </p>


        <div class="row">

            <span class="label">
                SMTP Host
            </span>

            <strong
                class="<?=
                    $smtpHost !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $smtpHost !== ''
                        ?
                        htmlspecialchars(
                            $smtpHost,
                            ENT_QUOTES,
                            'UTF-8'
                        )
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                SMTP Port
            </span>

            <strong
                class="<?=
                    $smtpPort !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $smtpPort !== ''
                        ?
                        htmlspecialchars(
                            $smtpPort,
                            ENT_QUOTES,
                            'UTF-8'
                        )
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                SMTP Username
            </span>

            <strong
                class="<?=
                    $smtpUsername !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $smtpUsername !== ''
                        ?
                        'Configured'
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                SMTP App Password
            </span>

            <strong
                class="<?=
                    $smtpPassword !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $smtpPassword !== ''
                        ?
                        'Configured'
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                Sender Address
            </span>

            <strong
                class="<?=
                    $mailFrom !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $mailFrom !== ''
                        ?
                        htmlspecialchars(
                            $mailFrom,
                            ENT_QUOTES,
                            'UTF-8'
                        )
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                Sender Name
            </span>

            <strong
                class="<?=
                    $mailFromName !== ''
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $mailFromName !== ''
                        ?
                        htmlspecialchars(
                            $mailFromName,
                            ENT_QUOTES,
                            'UTF-8'
                        )
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                PHP cURL
            </span>

            <strong
                class="<?=
                    $curlReady
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $curlReady
                        ?
                        'Enabled'
                        :
                        'Missing'
                ?>
            </strong>

        </div>


        <div class="row">

            <span class="label">
                SMTP Configuration
            </span>

            <strong
                class="<?=
                    $configReady
                        ?
                        'ok'
                        :
                        'bad'
                ?>"
            >
                <?=
                    $configReady
                        ?
                        'Ready'
                        :
                        'Incomplete'
                ?>
            </strong>

        </div>


        <div class="note">

            If SMTP Configuration shows
            <strong>Ready</strong>,
            your next step is installing PHPMailer
            and sending one test email to yourself.

        </div>

    </main>

</body>

</html>