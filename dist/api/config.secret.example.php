<?php
/**
 * Credentials template.
 *
 * ON DEPLOY: copy this file to api/config.secret.php on the server and fill it in.
 * config.secret.php is git-ignored on purpose: never commit credentials.
 */

return [
    // Password of the mailbox configured in config.php (smtp.username).
    'smtp_password' => 'PASTE-MAILBOX-PASSWORD-HERE',

    // Optional hCaptcha (https://dashboard.hcaptcha.com). Leave both empty to
    // disable it; honeypot, form token, rate limit and validation stay active.
    'hcaptcha_site_key' => '',
    'hcaptcha_secret'   => '',

    // true = never send e-mail; the message is written to storage/correo/.
    // Use only for local testing.
    'mail_dry_run' => false,
];
