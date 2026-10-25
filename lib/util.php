<?php
declare(strict_types=1);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nfc(string $s): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_C);
        if (is_string($n)) {
            return $n;
        }
    }
    return $s;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https');
}

/** The address phpBB itself sees (it ignores proxy headers). */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Best guess at the visitor for login throttling. The site sits behind
 * Cloudflare, so REMOTE_ADDR is an edge node. The header can be forged by
 * clients that bypass Cloudflare, which only weakens the per-IP limit; the
 * per-username limit still applies.
 */
function visitor_ip(): string
{
    $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    return filter_var($cf, FILTER_VALIDATE_IP) ? $cf : client_ip();
}

/**
 * Cookie name with the "__Host-" prefix on HTTPS: browsers only accept such a
 * cookie from this exact host (Secure, Path=/, no Domain), so pages on
 * the user-content host cannot plant or overwrite it ("cookie tossing").
 */
function cookie_name(string $base): string
{
    return is_https() ? '__Host-' . $base : $base;
}

/** @return array{expires?: int, path: string, secure: bool, httponly: bool, samesite: string} */
function cookie_options(int $expires = 0): array
{
    $o = [
        'path'     => is_https() ? '/' : pbput_config('base_path'), // __Host- requires Path=/
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    return $expires ? ['expires' => $expires] + $o : $o;
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(cookie_name('pbput'));
    session_set_cookie_params(['lifetime' => 0] + cookie_options());
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)pbput_config('session_lifetime'));
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    session_boot();
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        throw new UserError('err.csrf', [], 403);
    }
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https: http:; media-src 'self' https: http:; "
        . "frame-src 'self' blob: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; "
        . "connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

/** File based fixed-window counter, keyed by e.g. IP address. */
final class RateLimiter
{
    /** @var string */
    private $file;
    /** @var int */
    private $limit;
    /** @var int */
    private $window;

    public function __construct(string $bucket, string $key, int $limit, int $window)
    {
        $this->file = pbput_var_dir('ratelimit') . '/' . $bucket . '-' . hash('sha256', $key);
        $this->limit = $limit;
        $this->window = $window;
    }

    /**
     * Count one attempt: false, with nothing counted, once the limit is reached.
     * Checking and counting happen under one lock, so parallel requests can
     * neither all slip past the check nor overwrite each other's counts.
     */
    public function take(): bool
    {
        $ok = $this->update(function (array $d): array {
            if ($d['n'] >= $this->limit) {
                return [$d, false];
            }
            $d['n']++;
            return [$d, true];
        });
        if (random_int(1, 50) === 1) {
            $this->gc();
        }
        return $ok;
    }

    /** Give back an attempt from take() that should not count after all. */
    public function refund(): void
    {
        $this->update(static function (array $d): array {
            $d['n'] = max(0, $d['n'] - 1);
            return [$d, null];
        });
    }

    /**
     * Read-modify-write the counter under an exclusive lock.
     * $fn gets the current state and returns [new state, result].
     *
     * @return mixed $fn's result
     */
    private function update(callable $fn)
    {
        $fh = @fopen($this->file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new RuntimeException("rate limit file not writable: {$this->file}");
        }
        try {
            $d = json_decode((string)stream_get_contents($fh), true);
            if (!is_array($d) || ($d['start'] ?? 0) < time() - $this->window) {
                $d = ['start' => time(), 'n' => 0];
            }
            [$d, $result] = $fn($d);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($d));
            fflush($fh);
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function clear(): void
    {
        @unlink($this->file);
    }

    private function gc(): void
    {
        foreach (glob(dirname($this->file) . '/*') ?: [] as $f) {
            if (@filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}

function human_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float)$b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($i ? I18n::number($v, 1) : (string)$b) . "\u{00A0}" . $u[$i];
}

function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    if ($v === '') {
        return 0;
    }
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
}
