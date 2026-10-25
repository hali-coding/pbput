<?php
/*
 * pbput configuration. Copy to config.php and adjust.
 * Every key is optional; anything omitted falls back to the defaults in lib/bootstrap.php.
 */
return [
    // Display name used in the UI.
    'site_name' => 'example.com',
    'forum_url' => 'https://www.example.com/',

    // Language for visitors who are not signed in and whose browser asks for
    // none of the supported ones (lang/*.php). Signed-in users get the language
    // from their forum profile.
    'default_lang' => 'en',

    // Directory that contains phpBB's config.php. DB credentials and the table
    // prefix are read from it unless 'db' below is set.
    'phpbb_root' => '/var/www/example.com/forum',

    // Optional: use a dedicated read-only MySQL account instead of phpBB's own
    // (recommended, see README). Leave null to use phpBB's credentials.
    // 'db' => [
    //     'dsn'    => 'mysql:host=localhost;dbname=phpbb;charset=utf8mb4',
    //     'user'   => 'pbput_ro',
    //     'pass'   => 'secret',
    //     'prefix' => 'phpbb_',
    // ],

    // URL path the tool is served under on the forum host (must end with /).
    'base_path' => '/upload/',

    // Where user directories live on disk, and their public URL.
    'storage_root'    => '/var/www/files.example.com',
    'public_base_url' => 'https://files.example.com',

    // Private, writable directory for login throttling and partial uploads.
    // Must NOT be web accessible.
    'var_dir' => __DIR__ . '/var',

    // How a phpBB username maps to a directory name.
    //   'username'       – the display name, unsafe characters replaced by _
    //   'username_clean' – phpBB's lower-cased canonical name
    'dir_naming' => 'username',
    // Explicit mapping for users whose existing (FTP era) directory differs.
    // Keyed by phpBB user_id.
    'dir_overrides' => [
        // 2 => 'admin',
    ],
    'create_user_dirs' => true,

    // Restrict access to members of these phpBB group ids (empty = all active users).
    'allowed_groups' => [],

    // Storage limits. 0 = unlimited.
    'quota_bytes'     => 0,
    'quota_overrides' => [/* user_id => bytes */],
    'max_file_bytes'  => 2 * 1024 * 1024 * 1024,
];
