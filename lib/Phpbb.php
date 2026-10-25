<?php
declare(strict_types=1);

defined('PBPUT_ROOT') || exit;

/**
 * Read-only access to a phpBB 3.3 database: config, session cookies, users and
 * password hashes. Only SELECT statements are ever issued, so a MySQL account
 * with SELECT privileges is sufficient.
 */
final class Phpbb
{
    const ANONYMOUS = 1;
    const USER_NORMAL = 0;
    const USER_FOUNDER = 3;

    /** @var PDO */
    private $pdo;
    /** @var string */
    private $prefix;
    /** @var array|null */
    private $config = null;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public static function fromConfig(array $cfg): self
    {
        $db = $cfg['db'];
        if (!$db) {
            $db = self::readPhpbbConfig($cfg['phpbb_root']);
        }
        $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return new self($pdo, $db['prefix']);
    }

    /** Pull connection details out of phpBB's config.php without bootstrapping phpBB. */
    private static function readPhpbbConfig(string $root): array
    {
        $file = rtrim($root, '/') . '/config.php';
        if (!is_readable($file)) {
            throw new RuntimeException("Cannot read phpBB config: $file");
        }
        $c = (static function (string $__file): array {
            $dbhost = $dbport = $dbname = $dbuser = $dbpasswd = '';
            $table_prefix = 'phpbb_';
            include $__file;
            return compact('dbhost', 'dbport', 'dbname', 'dbuser', 'dbpasswd', 'table_prefix');
        })($file);

        $dsn = 'mysql:dbname=' . $c['dbname'];
        $host = $c['dbhost'] !== '' ? $c['dbhost'] : 'localhost';
        $port = (string)$c['dbport'];
        if ($host[0] === '/') {
            $dsn .= ';unix_socket=' . $host;
        } else {
            $dsn .= ';host=' . $host;
            if ($port !== '' && ctype_digit($port)) {
                $dsn .= ';port=' . $port;
            } elseif ($port !== '') {
                // mysqli driver in phpBB treats a non-numeric port as a socket path
                $dsn .= ';unix_socket=' . $port;
            }
        }
        // phpBB's tables are utf8mb3; mixing in utf8mb4 literals breaks comparisons.
        $dsn .= ';charset=utf8';
        return ['dsn' => $dsn, 'user' => $c['dbuser'], 'pass' => $c['dbpasswd'], 'prefix' => $c['table_prefix']];
    }

    private function t(string $table): string
    {
        return $this->prefix . $table;
    }

    private function row(string $sql, array $args = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** @return mixed */
    public function config(string $name, $default = null)
    {
        if ($this->config === null) {
            $this->config = [];
            $names = ['cookie_name', 'session_length', 'allow_autologin', 'max_autologin_time',
                'ip_check', 'browser_check', 'forwarded_for_check', 'sitename'];
            $in = implode(',', array_fill(0, count($names), '?'));
            $st = $this->pdo->prepare("SELECT config_name, config_value FROM {$this->t('config')} WHERE config_name IN ($in)");
            $st->execute($names);
            foreach ($st->fetchAll() as $r) {
                $this->config[$r['config_name']] = $r['config_value'];
            }
        }
        return $this->config[$name] ?? $default;
    }

    /* ------------------------------------------------------------------ */
    /* Cookie single sign-on                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Mirror of phpbb\session::session_begin()'s validation, minus every write.
     * Returns the user_id of the logged in forum user, or null.
     */
    public function userIdFromCookies(array $cookies, string $ip, string $userAgent, string $forwardedFor = ''): ?int
    {
        $name = (string)$this->config('cookie_name', 'phpbb3');
        $uid = (int)($cookies[$name . '_u'] ?? 0);
        $sid = (string)($cookies[$name . '_sid'] ?? '');
        $key = (string)($cookies[$name . '_k'] ?? '');
        if ($uid <= self::ANONYMOUS) {
            return null;
        }

        if (preg_match('/^[a-f0-9]{32}$/', $sid)) {
            $s = $this->row(
                "SELECT session_user_id, session_time, session_ip, session_browser, session_forwarded_for, session_autologin
                   FROM {$this->t('sessions')} WHERE session_id = ?",
                [$sid]
            );
            if ($s && (int)$s['session_user_id'] === $uid && $this->sessionMatches($s, $ip, $userAgent, $forwardedFor)) {
                return $uid;
            }
        }

        // No usable session: fall back to the "remember me" key, as phpBB does.
        if ($key !== '' && preg_match('/^[a-zA-Z0-9]{8,64}$/', $key) && $this->config('allow_autologin')) {
            $sql = "SELECT user_id FROM {$this->t('sessions_keys')} WHERE key_id = ? AND user_id = ?";
            $args = [md5($key), $uid];
            $max = (int)$this->config('max_autologin_time', 0);
            if ($max > 0) {
                $sql .= ' AND last_login >= ?';
                $args[] = time() - 86400 * $max;
            }
            if ($this->row($sql, $args)) {
                return $uid;
            }
        }
        return null;
    }

    private function sessionMatches(array $s, string $ip, string $ua, string $ff): bool
    {
        $ipCheck = (int)$this->config('ip_check', 0);
        if (!self::ipPrefixEqual((string)$s['session_ip'], $ip, $ipCheck)) {
            return false;
        }
        if ($this->config('browser_check')) {
            // phpBB stores the User-Agent trimmed and HTML-escaped (request::header()).
            $mine = htmlspecialchars(trim($ua), ENT_COMPAT, 'UTF-8');
            if (strtolower(trim(substr((string)$s['session_browser'], 0, 149))) !== strtolower(trim(substr($mine, 0, 149)))) {
                return false;
            }
        }
        if ($this->config('forwarded_for_check')) {
            $ff = preg_replace('# {2,}#', ' ', str_replace(',', ' ', $ff));
            if (substr((string)$s['session_forwarded_for'], 0, 254) !== substr(htmlspecialchars($ff, ENT_COMPAT, 'UTF-8'), 0, 254)) {
                return false;
            }
        }

        $now = time();
        if (!(int)$s['session_autologin']) {
            return (int)$s['session_time'] >= $now - ((int)$this->config('session_length', 3600) + 60);
        }
        if (!$this->config('allow_autologin')) {
            return false;
        }
        $max = (int)$this->config('max_autologin_time', 0);
        return !$max || (int)$s['session_time'] >= $now - 86400 * $max + 60;
    }

    private static function ipPrefixEqual(string $a, string $b, int $parts): bool
    {
        if ($parts <= 0) {
            return true;
        }
        if (strpos($a, ':') !== false || strpos($b, ':') !== false) {
            $pa = @inet_pton($a);
            $pb = @inet_pton($b);
            if ($pa === false || $pb === false) {
                return $a === $b;
            }
            // phpBB's short_ipv6() keeps 1 + ip_check blocks of 16 bits (all for 4).
            $bytes = $parts >= 4 ? 16 : 2 * (1 + $parts);
            return substr($pa, 0, $bytes) === substr($pb, 0, $bytes);
        }
        return array_slice(explode('.', $a), 0, $parts) === array_slice(explode('.', $b), 0, $parts);
    }

    /* ------------------------------------------------------------------ */
    /* Users                                                              */
    /* ------------------------------------------------------------------ */

    public function userById(int $id): ?array
    {
        return $this->row(
            "SELECT user_id, username, username_clean, user_type, user_password, user_lang FROM {$this->t('users')} WHERE user_id = ?",
            [$id]
        );
    }

    public function userByName(string $name): ?array
    {
        $sql = "SELECT user_id, username, username_clean, user_type, user_password, user_lang FROM {$this->t('users')} WHERE ";
        return $this->row($sql . 'username_clean = ?', [self::cleanUsername($name)])
            ?? $this->row($sql . 'username = ?', [$name]);
    }

    /** Approximation of phpBB's utf8_clean_string() that is exact for ASCII names. */
    public static function cleanUsername(string $s): string
    {
        $s = nfc($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /** Translation key for why this account may not use the tool, or null if it may. */
    public function denyReason(array $u, array $allowedGroups): ?string
    {
        $type = (int)$u['user_type'];
        if ((int)$u['user_id'] <= self::ANONYMOUS || ($type !== self::USER_NORMAL && $type !== self::USER_FOUNDER)) {
            return 'err.inactive';
        }
        $ban = $this->row(
            "SELECT ban_id FROM {$this->t('banlist')}
              WHERE ban_userid = ? AND ban_exclude = 0 AND (ban_end = 0 OR ban_end > ?) LIMIT 1",
            [(int)$u['user_id'], time()]
        );
        if ($ban) {
            return 'err.banned';
        }
        if ($allowedGroups && $type !== self::USER_FOUNDER) {
            $in = implode(',', array_fill(0, count($allowedGroups), '?'));
            $ok = $this->row(
                "SELECT group_id FROM {$this->t('user_group')} WHERE user_id = ? AND user_pending = 0 AND group_id IN ($in) LIMIT 1",
                array_merge([(int)$u['user_id']], array_map('intval', $allowedGroups))
            );
            if (!$ok) {
                return 'err.no_group';
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Passwords (port of phpbb\passwords\manager::check)                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $raw The password exactly as submitted.
     */
    public static function checkPassword(string $raw, string $hash): bool
    {
        if ($hash === '' || strlen($raw) > 4096) {
            return false;
        }
        // phpBB hashes what request->variable() returns: newlines normalised,
        // HTML-escaped, NFC-normalised.
        $pw = nfc(htmlspecialchars(
            strtr($raw, ["\r\n" => "\n", "\r" => "\n", "\0" => '', "\u{FEFF}" => '']),
            ENT_COMPAT,
            'UTF-8'
        ));

        if (!preg_match('#^\$([a-zA-Z0-9\\\\]*?)\$#', $hash, $m)) {
            return self::saltedMd5($pw, $hash);
        }
        $prefix = $m[1];

        if (strlen($prefix) > 2 && strpos($prefix, '\\') !== false) {
            return self::checkCombined($pw, explode('\\', $prefix), $hash);
        }

        switch ($prefix) {
            case 'CP':
                // Converted (phpBB2 era) hash: try every legacy algorithm phpBB knows.
                $inner = substr($hash, 4);
                $old = addslashes($raw);
                foreach ([$pw, md5($old), md5(self::toCp1252($old))] as $candidate) {
                    if (self::saltedMd5($candidate, $inner)) {
                        return true;
                    }
                }
                return strlen($inner) === 32
                    && (hash_equals($inner, md5($old)) || hash_equals($inner, md5(self::toCp1252($old))));
            case 'H':
            case 'P':
                return self::saltedMd5($pw, $hash);
            case '2a':
            case '2y':
            case 'argon2i':
            case 'argon2id':
                return password_verify($pw, $hash);
        }
        return false;
    }

    /** e.g. $H\2y$<phpass settings>$<bcrypt settings>$<hash>: bcrypt(phpass(password)). */
    private static function checkCombined(string $pw, array $types, string $hash, bool $skipPhpbb2 = false): bool
    {
        // Passwords converted from phpBB2 may already be phpass(md5(password)).
        if (!$skipPhpbb2 && self::checkCombined(md5($pw), $types, $hash, true)) {
            return true;
        }
        preg_match('#^\$([a-zA-Z0-9\\\\]*?)\$#', $hash, $m);
        $rest = substr($hash, strlen($m[0]));
        $settings = [];
        foreach ($types as $_) {
            $p = strpos($rest, '$');
            $settings[] = substr($rest, 0, $p !== false ? $p : strlen($rest));
            $rest = (string)substr($rest, $p + 1);
        }

        $value = $pw;
        foreach ($types as $i => $type) {
            $rebuilt = '$' . $type . '$' . str_replace('\\', '$', $settings[$i]);
            switch ($type) {
                case 'H':
                case 'P':
                    $out = self::phpassHash($value, $rebuilt);
                    break;
                case '2a':
                case '2y':
                    $out = crypt($value, $rebuilt);
                    $out = strlen($out) >= 60 ? $out : null;
                    break;
                default:
                    return false;
            }
            if ($out === null) {
                return false;
            }
            $value = str_replace($rebuilt, '', $out);
        }
        return hash_equals($hash, '$' . implode('\\', $types) . '$' . implode('$', $settings) . '$' . $value);
    }

    private static function saltedMd5(string $pw, string $hash): bool
    {
        if (strlen($hash) !== 34) {
            return strlen($hash) === 32 && hash_equals($hash, md5($pw));
        }
        $out = self::phpassHash($pw, $hash);
        return $out !== null && hash_equals($hash, $out);
    }

    const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Portable phpass hash, $setting being "$H$" + count char + 8 char salt (or a full hash). */
    private static function phpassHash(string $pw, string $setting): ?string
    {
        if (strlen($setting) < 12) {
            return null;
        }
        $log2 = strpos(self::ITOA64, $setting[3]);
        if ($log2 === false || $log2 < 7 || $log2 > 30) {
            return null;
        }
        $count = 1 << $log2;
        $salt = substr($setting, 4, 8);
        $h = md5($salt . $pw, true);
        do {
            $h = md5($h . $pw, true);
        } while (--$count);
        return substr($setting, 0, 12) . self::encode64($h, 16);
    }

    private static function encode64(string $in, int $count): string
    {
        $out = '';
        $i = 0;
        do {
            $v = ord($in[$i++]);
            $out .= self::ITOA64[$v & 0x3f];
            if ($i < $count) {
                $v |= ord($in[$i]) << 8;
            }
            $out .= self::ITOA64[($v >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $v |= ord($in[$i]) << 16;
            }
            $out .= self::ITOA64[($v >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $out .= self::ITOA64[($v >> 18) & 0x3f];
        } while ($i < $count);
        return $out;
    }

    private static function toCp1252(string $s): string
    {
        $r = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($r) ? $r : $s;
    }
}
