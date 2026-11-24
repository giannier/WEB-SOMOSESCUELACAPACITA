<?php
/**
 * Form defenses: per-IP rate limit, session token, honeypot.
 * File-based on purpose: shared cPanel hosting has no guaranteed Redis/DB.
 */

declare(strict_types=1);

final class Security
{
    private array $limits;
    private string $rateDir;
    /** Reservation made by the last successful reserve(), so release() can undo exactly that one. */
    private ?array $held = null;

    /** Ranges published by Cloudflare (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function __construct(array $config)
    {
        $this->limits = $config['limits'];
        $this->rateDir = $config['storage'] . '/ratelimit';
        if (!is_dir($this->rateDir)) {
            mkdir($this->rateDir, 0750, true);
        }
    }

    /**
     * Real visitor IP. REMOTE_ADDR cannot be forged; CF-Connecting-IP is only
     * trusted when the connection comes from a Cloudflare range.
     * X-Forwarded-For is never used.
     */
    public function ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && $this->inCloudflare($remote)) {
            return $cf;
        }
        return $remote;
    }

    private function inCloudflare(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach (self::CLOUDFLARE as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($bin, $netBin, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Server-private salt, generated on first use inside storage/ (closed to HTTP). */
    private function salt(): string
    {
        static $salt = null;
        if ($salt !== null) {
            return $salt;
        }
        $file = $this->rateDir . '/.salt';
        $value = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if (strlen($value) < 32) {
            $fresh = bin2hex(random_bytes(32));
            $fp = @fopen($file, 'x'); // fails if another process created it first
            if ($fp !== false) {
                fwrite($fp, $fresh);
                fclose($fp);
                @chmod($file, 0600);
            }
            $value = trim((string) @file_get_contents($file));
            if (strlen($value) < 32) {
                $value = $fresh;
            }
        }
        return $salt = $value;
    }

    private function key(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->salt());
    }

    /** Non-reversible IP fingerprint stored with each archived claim. */
    public function ipFingerprint(string $ip): string
    {
        return $this->key($ip);
    }

    /**
     * Checks limits and reserves the slot atomically under flock: per-IP
     * interval and daily cap, global hourly/daily caps, repeated content in
     * 24 h. Fail-closed if the store cannot be opened.
     */
    public function reserve(string $ip, string $content, ?string &$reason = null): bool
    {
        $now = time();
        $fp = @fopen($this->rateDir . '/state.json', 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if ($fp !== false) {
                fclose($fp);
            }
            $reason = 'store';
            return false;
        }

        try {
            $state = json_decode((string) stream_get_contents($fp), true);
            $state = is_array($state) ? $state : [];
            $fresh = static fn($t) => is_int($t) && $t > $now - 86400;

            $ips = [];
            foreach ((array) ($state['ips'] ?? []) as $k => $stamps) {
                $kept = array_values(array_filter((array) $stamps, $fresh));
                if ($kept !== []) {
                    $ips[$k] = $kept;
                }
            }
            $global = array_values(array_filter((array) ($state['global'] ?? []), $fresh));
            $dups = array_filter((array) ($state['dups'] ?? []), $fresh);

            $k = $this->key($ip);
            $stamps = $ips[$k] ?? [];
            $fingerprint = hash_hmac('sha256', $content, $this->salt());
            $lastHour = count(array_filter($global, fn($t) => $t > $now - 3600));

            if ($lastHour >= $this->limits['global_per_hour']) {
                $reason = 'global_hour';
            } elseif (count($global) >= $this->limits['global_per_day']) {
                $reason = 'global_day';
            } elseif ($stamps !== [] && max($stamps) > $now - $this->limits['interval_seconds']) {
                $reason = 'interval';
            } elseif (count($stamps) >= $this->limits['per_day']) {
                $reason = 'day';
            } elseif (isset($dups[$fingerprint])) {
                $reason = 'duplicate';
            } else {
                $stamps[] = $now;
                $ips[$k] = $stamps;
                $global[] = $now;
                $dups[$fingerprint] = $now;
                $reason = null;
                $this->held = ['key' => $k, 'at' => $now, 'fingerprint' => $fingerprint];
            }

            if ($reason === null) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(['ips' => $ips, 'global' => $global, 'dups' => $dups]));
                fflush($fp);
            }
            return $reason === null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Undoes the reservation of the last successful reserve() (per-IP slot, global slot, content fingerprint) when
     * the claim could not be registered afterwards, so the user's retry is not rejected for a claim that never
     * existed. Never called once the claim is archived. Best effort: a failure here only keeps the old behaviour.
     */
    public function release(): void
    {
        $held = $this->held;
        if ($held === null) {
            return;
        }
        $this->held = null;
        $fp = @fopen($this->rateDir . '/state.json', 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if ($fp !== false) {
                fclose($fp);
            }
            return;
        }
        try {
            $state = json_decode((string) stream_get_contents($fp), true);
            if (!is_array($state)) {
                return;
            }
            $dropOne = static function (array $stamps, int $at): array {
                $i = array_search($at, $stamps, true);
                if ($i !== false) {
                    unset($stamps[$i]);
                }
                return array_values($stamps);
            };
            $ips = (array) ($state['ips'] ?? []);
            $global = (array) ($state['global'] ?? []);
            $dups = (array) ($state['dups'] ?? []);
            if (isset($ips[$held['key']])) {
                $ips[$held['key']] = $dropOne((array) $ips[$held['key']], $held['at']);
                if ($ips[$held['key']] === []) {
                    unset($ips[$held['key']]);
                }
            }
            $global = $dropOne($global, $held['at']);
            if (($dups[$held['fingerprint']] ?? null) === $held['at']) {
                unset($dups[$held['fingerprint']]);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['ips' => $ips, 'global' => $global, 'dups' => $dups]));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // --- Form token ------------------------------------------------------

    /** Issues a session-bound token and records the issue time. */
    public function issueToken(): string
    {
        $this->session();
        $token = bin2hex(random_bytes(32));
        $_SESSION['lr_token'] = $token;
        $_SESSION['lr_issued'] = time();
        return $token;
    }

    /** Validates the token and the time taken to fill the form. */
    public function tokenValid(string $token, ?string &$reason = null): bool
    {
        $this->session();
        $stored = $_SESSION['lr_token'] ?? '';
        $issued = (int) ($_SESSION['lr_issued'] ?? 0);

        if ($stored === '' || !hash_equals($stored, $token)) {
            $reason = 'token';
            return false;
        }
        $elapsed = time() - $issued;
        if ($elapsed < $this->limits['min_fill_seconds']) {
            $reason = 'too_fast';
            return false;
        }
        if ($elapsed > $this->limits['token_ttl']) {
            $reason = 'token_expired';
            return false;
        }
        return true;
    }

    /** A token is single-use. */
    public function consumeToken(): void
    {
        $this->session();
        unset($_SESSION['lr_token'], $_SESSION['lr_issued']);
    }

    private function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('ECLR');
        session_start();
    }

    /** Hidden trap field: a human never fills it. */
    public function honeypotClean(array $post): bool
    {
        return trim((string) ($post['website_alt'] ?? '')) === '';
    }
}
