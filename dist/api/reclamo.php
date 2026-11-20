<?php
/**
 * Libro de Reclamaciones endpoint.
 *
 * Checks run from cheapest to most expensive: illegitimate submissions are
 * discarded first; the PDF and the e-mail (costly) come last.
 *
 * Messages sent to the browser are deliberately generic. Technical detail
 * goes to the server log: telling an attacker which check failed hands them
 * the map.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);
ini_set('error_log', $config['storage'] . '/log/php-error.log');

require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Captcha.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/ClaimNumber.php';
require_once __DIR__ . '/src/ClaimPdf.php';
require_once __DIR__ . '/src/Notifier.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function note(array $config, string $level, string $message): void
{
    $dir = $config['storage'] . '/log';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/claims.log', sprintf("[%s] %-7s %s\n", date('c'), $level, $message), FILE_APPEND | LOCK_EX);
}

$security = new Security($config);
$ip = $security->ip();

// 1. Method and size -------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'message' => 'Método no permitido.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    note($config, 'BLOCK', "oversized body from $ip");
    respond(413, ['ok' => false, 'message' => 'La solicitud es demasiado grande.']);
}

// 2. Same origin (Origin, falling back to Referer) --------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === '') {
    $origin = $_SERVER['HTTP_REFERER'] ?? '';
}
if ($origin === '') {
    note($config, 'BLOCK', "no origin/referer from $ip");
    respond(403, ['ok' => false, 'message' => 'Solicitud no autorizada.']);
}
// parse_url drops the port while HTTP_HOST keeps it when non-standard.
$originHost = parse_url($origin, PHP_URL_HOST) ?: '';
if ($originPort = parse_url($origin, PHP_URL_PORT)) {
    $originHost .= ':' . $originPort;
}
$ownHost = $_SERVER['HTTP_HOST'] ?? '';
$accepted = [$ownHost, 'www.' . $ownHost];
if (str_starts_with(strtolower($ownHost), 'www.')) {
    $accepted[] = substr($ownHost, 4);
}
$match = false;
foreach ($accepted as $candidate) {
    if ($candidate !== '' && strcasecmp($originHost, $candidate) === 0) {
        $match = true;
        break;
    }
}
if (!$match) {
    note($config, 'BLOCK', "cross origin '$origin' from $ip");
    respond(403, ['ok' => false, 'message' => 'Solicitud no autorizada.']);
}

// 3. Honeypot ---------------------------------------------------------------
if (!$security->honeypotClean($_POST)) {
    note($config, 'BOT', "honeypot filled from $ip");
    // Answer as if it worked: a bot that sees the rejection adapts and returns.
    respond(200, ['ok' => true, 'claim_number' => 'LR-0000-000000']);
}

// 4. Session token and fill time --------------------------------------------
$reason = null;
if (!$security->tokenValid((string) ($_POST['token'] ?? ''), $reason)) {
    note($config, 'BLOCK', "invalid token ($reason) from $ip");
    if ($reason === 'token_expired') {
        respond(419, ['ok' => false, 'message' => 'El formulario expiró. Recarga la página e inténtalo de nuevo.']);
    }
    respond(403, ['ok' => false, 'message' => 'No pudimos validar el formulario. Recarga la página e inténtalo de nuevo.']);
}

// 5. Field validation -------------------------------------------------------
$validator = new Validator();
if (!$validator->validate($_POST)) {
    respond(422, ['ok' => false, 'message' => 'Revisa los campos marcados.', 'errors' => $validator->errors()]);
}
$data = $validator->data();

// 6. hCaptcha (only when keys are configured; fail-closed once enabled) -----
$captcha = new Captcha($config);
if ($captcha->enabled() && !$captcha->verify((string) ($_POST['h-captcha-response'] ?? ''), $ip, $reason)) {
    note($config, 'BLOCK', "captcha failed ($reason) from $ip");
    respond(400, ['ok' => false, 'message' => 'No pudimos verificar que eres una persona. Marca la casilla de verificación e inténtalo de nuevo.']);
}

// 7. Per-IP / global limits and repeated content (atomic reservation) --------
$contentKey = mb_strtolower($data['email'] . '|' . $data['name'] . '|' . $data['detail'] . '|' . $data['request']);
if (!$security->reserve($ip, $contentKey, $reason)) {
    note($config, 'LIMIT', "limit '$reason' reached by $ip");
    respond(429, [
        'ok' => false,
        'message' => 'Has registrado varios reclamos en poco tiempo. Inténtalo más tarde o escríbenos a '
            . $config['smtp']['internal_bcc'] . '.',
    ]);
}

// 8. Claim number -----------------------------------------------------------
try {
    $claimNumber = (new ClaimNumber($config['storage']))->next();
} catch (Throwable $e) {
    note($config, 'ERROR', 'claim number: ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'No pudimos registrar el reclamo. Inténtalo en unos minutos.']);
}

$months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
           'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$sheet = $data + [
    'claim_number' => $claimNumber,
    'date_iso'     => date('c'),
    'date_long'    => sprintf('%s de %s de %s, %s', date('j'), $months[(int) date('n')], date('Y'), date('H:i')),
    // Short code so the consumer can quote the sheet without exposing data.
    'verification' => strtoupper(substr(hash('sha256', $claimNumber . $data['email'] . date('c')), 0, 10)),
];

// 9. PDF (audited to one page) ----------------------------------------------
try {
    $audit = null;
    $pdf = ClaimPdf::generate($config['provider'], $sheet, $config['response_business_days'], $audit);
    if (($audit['pages'] ?? 0) !== 1) {
        note($config, 'WARN', sprintf('%s spans %d pages', $claimNumber, $audit['pages']));
    }
    if (!empty($audit['trimmed'])) {
        note($config, 'WARN', $claimNumber . ' text trimmed to fit one page');
    }
} catch (Throwable $e) {
    note($config, 'ERROR', 'pdf: ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'No pudimos generar la constancia. Inténtalo en unos minutos.']);
}

// 10. Archive BEFORE sending: if SMTP fails the claim is not lost (Art. 12: 2 years)
$dir = $config['storage'] . '/hojas/' . date('Y');
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
@file_put_contents($dir . '/' . $claimNumber . '.pdf', $pdf, LOCK_EX);
@file_put_contents(
    $dir . '/' . $claimNumber . '.json',
    json_encode($sheet + ['ip_hash' => $security->ipFingerprint($ip)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);
$security->consumeToken();

// 11. Send ------------------------------------------------------------------
$notifier = new Notifier($config);
$sent = false;
if (!$notifier->configured()) {
    note($config, 'ERROR', $claimNumber . ' NOT sent: SMTP password missing (config.secret.php)');
} else {
    try {
        $result = $notifier->send($sheet, $pdf);
        $sent = true;
        note($config, 'OK', $claimNumber . ($notifier->dryRun() ? ' DRY-RUN e-mail written to ' . basename($result) : ' sent to ' . $sheet['email']));
    } catch (Throwable $e) {
        note($config, 'ERROR', $claimNumber . ' send failed: ' . $e->getMessage());
    }
}

// The claim is registered even if the e-mail fails; the failure is in the log.
respond(200, [
    'ok'           => true,
    'claim_number' => $claimNumber,
    'sent'         => $sent,
    'message'      => $sent
        ? 'Tu reclamo quedó registrado. Te enviamos la constancia en PDF a tu correo.'
        : 'Tu reclamo quedó registrado con el número ' . $claimNumber
          . '. No pudimos enviarte el correo en este momento; guarda este número como constancia.',
]);
