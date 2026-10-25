<?php
declare(strict_types=1);

defined('PBPUT_ROOT') || exit;

/**
 * Translations from lang/<code>.php. The language is the user's phpBB profile
 * language once signed in; before that it is guessed from the last sign-in
 * (cookie), the browser and finally the configured default.
 */
final class I18n
{
    const COOKIE = 'pbput_lang';

    /** @var string|null */
    private static $lang = null;
    /** @var array<string, array> */
    private static $strings = [];

    public static function supported(): array
    {
        return array_map(static function (string $f) {
            return basename($f, '.php');
        }, glob(PBPUT_ROOT . '/lang/*.php') ?: []);
    }

    /** Map a phpBB/browser code ("sv", "en_us", "sv-SE") to a supported one. */
    public static function normalize(string $code): ?string
    {
        $code = strtolower(substr(preg_replace('/[^A-Za-z_-]/', '', $code), 0, 2));
        return in_array($code, self::supported(), true) ? $code : null;
    }

    public static function lang(): string
    {
        if (self::$lang === null) {
            self::$lang = self::detect();
        }
        return self::$lang;
    }

    public static function set(string $code): void
    {
        self::$lang = self::normalize($code) ?? self::lang();
    }

    /** Remember the profile language so the login page uses it next time. */
    public static function remember(string $code): void
    {
        $code = self::normalize($code);
        if ($code !== null && ($_COOKIE[cookie_name(self::COOKIE)] ?? '') !== $code) {
            setcookie(cookie_name(self::COOKIE), $code, cookie_options(time() + 365 * 86400));
        }
    }

    private static function detect(): string
    {
        $fromCookie = self::normalize((string)($_COOKIE[cookie_name(self::COOKIE)] ?? ''));
        if ($fromCookie !== null) {
            return $fromCookie;
        }
        // Accept-Language: "sv-SE,sv;q=0.9,en;q=0.8"
        $prefs = [];
        foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $bits = explode(';q=', trim($part));
            $code = self::normalize($bits[0]);
            if ($code !== null) {
                $q = isset($bits[1]) ? (float)$bits[1] : 1.0;
                $prefs[$code] = max($prefs[$code] ?? 0, $q);
            }
        }
        if ($prefs) {
            arsort($prefs);
            return (string)array_key_first($prefs);
        }
        return self::normalize((string)pbput_config('default_lang')) ?? 'en';
    }

    public static function strings(?string $lang = null): array
    {
        $lang = $lang ?? self::lang();
        if (!isset(self::$strings[$lang])) {
            $own = require PBPUT_ROOT . "/lang/$lang.php";
            // Fall back to English for anything missing.
            self::$strings[$lang] = $lang === 'en' ? $own : $own + self::strings('en');
        }
        return self::$strings[$lang];
    }

    public static function t(string $key, array $args = []): string
    {
        $s = self::strings();
        if (isset($args['n']) && isset($s[$key . ((int)$args['n'] === 1 ? '.one' : '.other')])) {
            $key .= (int)$args['n'] === 1 ? '.one' : '.other';
        }
        $text = $s[$key] ?? $key;
        if (!$args) {
            return $text;
        }
        $map = [];
        foreach ($args as $k => $v) {
            $map['{' . $k . '}'] = (string)$v;
        }
        return strtr($text, $map);
    }

    public static function number(float $v, int $decimals): string
    {
        return number_format($v, $decimals, self::t('_decimal'), "\u{00A0}");
    }
}

function t(string $key, array $args = []): string
{
    return I18n::t($key, $args);
}

/** Escaped translation; values in $html are inserted unescaped. */
function th(string $key, array $html = []): string
{
    $out = h(I18n::t($key));
    foreach ($html as $k => $v) {
        $out = str_replace('{' . $k . '}', $v, $out);
    }
    return $out;
}
