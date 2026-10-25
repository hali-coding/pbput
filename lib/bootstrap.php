<?php
declare(strict_types=1);

define('PBPUT_ROOT', dirname(__DIR__));
define('PBPUT_VERSION', '1.12.0');

require __DIR__ . '/util.php';
require __DIR__ . '/I18n.php';
require __DIR__ . '/Phpbb.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Sanitizer.php';
require __DIR__ . '/Storage.php';

/**
 * Error meant for the user. Holds a translation key; the text is produced by
 * message() so it follows the language chosen once the user is known.
 */
final class UserError extends RuntimeException
{
    /** @var array */
    public $args;
    /** @var int */
    public $status;
    /** @var array */
    public $extra;

    public function __construct(string $key, array $args = [], int $status = 400, array $extra = [])
    {
        parent::__construct($key);
        $this->args = $args;
        $this->status = $status;
        $this->extra = $extra;
    }

    public function message(): string
    {
        return t($this->getMessage(), $this->args);
    }
}

function pbput_defaults(): array
{
    return [
        'site_name'        => 'pbput',
        'default_lang'     => 'en',
        'forum_url'        => '/',
        'phpbb_root'       => '/var/www/html',
        'db'               => null,
        'base_path'        => '/upload/',
        'storage_root'     => '/var/www/user',
        'public_base_url'  => '',
        'var_dir'          => PBPUT_ROOT . '/var',
        'dir_naming'       => 'username',
        'dir_overrides'    => [],
        'reserved_dirs'    => [],
        'create_user_dirs' => true,
        'dir_mode'         => 0775,
        'file_mode'        => 0664,
        'allowed_groups'   => [],
        'quota_bytes'      => 0,
        'quota_overrides'  => [],
        'max_file_bytes'   => 2 * 1024 * 1024 * 1024,
        'chunk_bytes'      => 8 * 1024 * 1024,
        'max_layout_bytes' => 64 * 1024,
        'blocked_extensions' => [
            // Server-side code
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc',
            'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'shtml', 'shtm', 'stm',
            // Web pages and active content: the user-content host shares files, it does not host sites
            'html', 'htm', 'xhtml', 'xht', 'mht', 'mhtml', 'hta', 'js', 'mjs', 'swf', 'svg', 'svgz', 'xsl', 'xslt',
        ],
        'manage_htaccess'     => true,
        'session_lifetime'    => 8 * 3600,
        'revalidate_interval' => 300,
        'login_attempts'      => 8,
        'login_window'        => 900,
    ];
}

/** @return mixed */
function pbput_config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = getenv('PBPUT_CONFIG') ?: PBPUT_ROOT . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('pbput: config.php is missing (copy config.sample.php).');
        }
        // Plain merge: list-type values (groups, extensions) replace defaults wholesale.
        $cfg = array_merge(pbput_defaults(), require $file);
        $cfg['base_path'] = '/' . trim($cfg['base_path'], '/') . '/';
        $cfg['base_path'] = $cfg['base_path'] === '//' ? '/' : $cfg['base_path'];
        $cfg['storage_root'] = rtrim($cfg['storage_root'], '/');
        $cfg['public_base_url'] = rtrim($cfg['public_base_url'], '/');
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function pbput_phpbb(): Phpbb
{
    static $db = null;
    if ($db === null) {
        $db = Phpbb::fromConfig(pbput_config());
    }
    return $db;
}

function pbput_var_dir(string $sub = ''): string
{
    $dir = rtrim(pbput_config('var_dir'), '/') . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("var_dir not writable: $dir");
    }
    return $dir;
}
