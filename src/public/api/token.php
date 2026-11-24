<?php
/**
 * Issues the token the form must send back with the claim.
 *
 * Requested via fetch when the page loads, so the token is born when a person
 * opens the form and is bound to their session. A bot that POSTs straight to
 * reclamo.php has no valid token.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Captcha.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// Kill switch (see reclamo.php): storage/.disabled stops issuing tokens too.
if (is_file($config['storage'] . '/.disabled')) {
    http_response_code(503);
    header('Retry-After: 3600');
    echo json_encode(['ok' => false, 'message' => 'unavailable']);
    exit;
}

$security = new Security($config);

echo json_encode([
    'token'           => $security->issueToken(),
    'min_wait'        => $config['limits']['min_fill_seconds'],
    // hCaptcha public key ('' = captcha disabled). The secret never leaves the server.
    'captcha_sitekey' => (new Captcha($config))->siteKey(),
], JSON_UNESCAPED_UNICODE);
