<?php
/**
 * Server-side hCaptcha verification (optional, enabled when both keys exist).
 * Fail-closed: any HTTP/parse/hostname problem returns false.
 */

declare(strict_types=1);

final class Captcha
{
    private const VERIFY_URL = 'https://api.hcaptcha.com/siteverify';

    private string $siteKey;
    private string $secret;
    private string $domain;
    private bool $allowLocalhost;

    public function __construct(array $config)
    {
        $c = $config['captcha'] ?? [];
        $this->siteKey = trim((string) ($c['site_key'] ?? ''));
        $this->secret = trim((string) ($c['secret'] ?? ''));
        $this->domain = strtolower(trim((string) ($c['domain'] ?? '')));
        $this->allowLocalhost = ($c['allow_localhost'] ?? false) === true;
    }

    public function enabled(): bool
    {
        return $this->siteKey !== '' && $this->secret !== '';
    }

    public function siteKey(): string
    {
        return $this->enabled() ? $this->siteKey : '';
    }

    public function verify(string $response, string $ip, ?string &$reason = null): bool
    {
        if (!$this->enabled()) {
            $reason = 'captcha_disabled';
            return false;
        }
        $response = trim($response);
        if ($response === '' || strlen($response) > 4096) {
            $reason = 'captcha_empty';
            return false;
        }

        $raw = $this->post(http_build_query([
            'secret' => $this->secret, 'response' => $response,
            'sitekey' => $this->siteKey, 'remoteip' => $ip,
        ], '', '&'));
        if ($raw === null) {
            $reason = 'captcha_no_response';
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            $reason = 'captcha_rejected';
            return false;
        }

        $host = strtolower((string) ($data['hostname'] ?? ''));
        if ($host !== '') {
            $allowed = [$this->domain, 'www.' . $this->domain];
            if ($this->allowLocalhost) {
                $allowed[] = 'localhost';
            }
            if ($this->domain === '' || !in_array($host, $allowed, true)) {
                $reason = 'captcha_hostname';
                return false;
            }
        }
        return true;
    }

    private function post(string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init(self::VERIFY_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $out = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return (is_string($out) && $code === 200) ? $out : null;
        }
        $ctx = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                       'content' => $body, 'timeout' => 10, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $out = @file_get_contents(self::VERIFY_URL, false, $ctx);
        return is_string($out) ? $out : null;
    }
}
