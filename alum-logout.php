<?php
/** TRACEGRAD - Alumni Logout */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/shared/session-security.php';

tgDestroyCurrentSession();
header('Location: alum-login.php?loggedout=1');
exit;
