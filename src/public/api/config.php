<?php
/**
 * Libro de Reclamaciones - configuration (no secrets in this file).
 *
 * Credentials live ONLY in api/config.secret.php (git-ignored). See
 * config.secret.example.php. Values marked TODO must be completed before go-live.
 */

declare(strict_types=1);

// --- Secrets -------------------------------------------------------------
$secrets = [
    'smtp_password'     => '',
    'hcaptcha_site_key' => '',
    'hcaptcha_secret'   => '',
    'mail_dry_run'      => false,
];
if (is_file(__DIR__ . '/config.secret.php')) {
    $loaded = require __DIR__ . '/config.secret.php';
    if (is_array($loaded)) {
        foreach ($secrets as $key => $default) {
            if (isset($loaded[$key]) && gettype($loaded[$key]) === gettype($default)) {
                $secrets[$key] = $loaded[$key];
            }
        }
    }
}

// Test switch: build + archive the PDF and write the e-mail to
// storage/correo/ instead of sending it. Also enabled by env LR_MAIL_DRY_RUN=1.
$dryRun = $secrets['mail_dry_run'] || getenv('LR_MAIL_DRY_RUN') === '1';

return [

    // Provider data printed on every Hoja de Reclamación (Art. 5, D.S. 011-2011-PCM).
    'provider' => [
        'trade_name' => 'Escuela Capacita',                          // site name (WordPress blogname)
        'legal_name' => 'CAPACITA ESCUELA EJECUTIVA DE FORMACION SOCIEDAD ANONIMA CERRADA',
        'ruc'        => '20605956000',
        'address'    => 'AV. GENERAL ALVAREZ DE ARENALES NRO. 480 URB. SANTA BEATRIZ (OFICINA 301), JESUS MARIA, LIMA - LIMA, PERÚ',
        'web'        => 'somosescuelacapacita.com',
        'phone'      => '984 423 725',                               // site header
        'logo'       => __DIR__ . '/assets/logo.png',                // header logo (purple/yellow)
    ],

    'smtp' => [
        'host'        => 'mail.somosescuelacapacita.com',            // TODO: confirm in cPanel
        'port'        => 465,
        'security'    => 'ssl',                                      // 465 = implicit SSL; 587 would be 'tls'
        // Dedicated mailbox for the complaint book (must exist in cPanel).
        'username'    => 'reclamaciones@somosescuelacapacita.com',
        'password'    => $secrets['smtp_password'],
        'from_name'   => 'Escuela Capacita - Libro de Reclamaciones',
        'internal_bcc' => 'reclamaciones@somosescuelacapacita.com',  // hidden copy to the company
        'verify_certificate' => true,
        'timeout'     => 20,
        'dry_run'     => $dryRun,
    ],

    // hCaptcha is enabled only when both keys are present in config.secret.php.
    'captcha' => [
        'site_key'        => $secrets['hcaptcha_site_key'],
        'secret'          => $secrets['hcaptcha_secret'],
        'domain'          => 'somosescuelacapacita.com',
        'allow_localhost' => false,
    ],

    'limits' => [
        'interval_seconds' => 1800,  // 1 claim per IP every 30 min
        'per_day'          => 5,     // and 5 per IP per 24 h
        'global_per_hour'  => 20,
        'global_per_day'   => 80,
        'min_fill_seconds' => 6,     // faster than this = bot
        'token_ttl'        => 7200,
    ],

    // Ley 31435 amended art. 24 of Ley 29571: 15 business days, not extendable.
    'response_business_days' => 15,

    'storage'         => __DIR__ . '/storage',
    'retention_years' => 2,           // Art. 12, D.S. 011-2011-PCM
    'timezone'        => 'America/Lima',
];
