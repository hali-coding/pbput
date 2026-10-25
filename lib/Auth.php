<?php
declare(strict_types=1);

defined('PBPUT_ROOT') || exit;

/**
 * The tool keeps its own PHP session; phpBB is only consulted to establish it
 * (cookie SSO or username/password) and to periodically confirm the account
 * is still active.
 */
final class Auth
{
    /** Current user from our session, attempting forum SSO if there is none. */
    public static function user(bool $trySso = true): ?array
    {
        session_boot();
        $u = $_SESSION['user'] ?? null;
        $cfg = pbput_config();

        if ($u) {
            if (time() - $u['auth_at'] > $cfg['session_lifetime']) {
                self::logout();
                return null;
            }
            if (time() - $u['checked_at'] > $cfg['revalidate_interval']) {
                $row = pbput_phpbb()->userById($u['id']);
                if (!$row || pbput_phpbb()->denyReason($row, $cfg['allowed_groups']) !== null) {
                    self::logout();
                    return null;
                }
                $_SESSION['user']['checked_at'] = time();
                // Picks up a language change made in the forum profile.
                $_SESSION['user']['lang'] = (string)$row['user_lang'];
            }
            if (Storage::resolveUserDir($u['dir']) === null) {
                // The folder was removed, renamed or replaced (e.g. by a symlink)
                // since sign-in: map it again (creating it if needed) instead of
                // failing every request.
                $dirs = Storage::dirsForUser($u['id'], $u['name']);
                $_SESSION['user']['dirs'] = $dirs;
                $_SESSION['user']['dir'] = $dirs[0];
            }
            return $_SESSION['user'];
        }

        if ($trySso && empty($_COOKIE[cookie_name('pbput_nosso')])) {
            $db = pbput_phpbb();
            $uid = $db->userIdFromCookies(
                $_COOKIE,
                client_ip(),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')
            );
            if ($uid !== null) {
                $row = $db->userById($uid);
                if ($row && $db->denyReason($row, $cfg['allowed_groups']) === null) {
                    return self::establish($row, 'forum');
                }
            }
        }
        return null;
    }

    /** @return array The user on success. Throws UserError otherwise. */
    public static function login(string $username, string $password): array
    {
        $cfg = pbput_config();
        $username = trim($username);
        $byIp = new RateLimiter('login-ip', visitor_ip(), $cfg['login_attempts'] * 3, $cfg['login_window']);
        $byName = new RateLimiter('login-user', Phpbb::cleanUsername($username), $cfg['login_attempts'], $cfg['login_window']);
        // Every attempt is counted up front (failures keep their count, anything
        // else gives it back), so parallel requests cannot outrun the limits.
        if (!$byIp->take()) {
            throw new UserError('err.rate_limited', [], 429);
        }
        if (!$byName->take()) {
            $byIp->refund();
            throw new UserError('err.rate_limited', [], 429);
        }
        if ($username === '' || $password === '') {
            $byIp->refund();
            $byName->refund();
            throw new UserError('err.missing_credentials');
        }

        $db = pbput_phpbb();
        $row = $db->userByName($username);
        if (!$row || !Phpbb::checkPassword($password, (string)$row['user_password'])) {
            throw new UserError('err.bad_credentials', [], 401);
        }
        $byIp->refund();
        $why = $db->denyReason($row, $cfg['allowed_groups']);
        if ($why !== null) {
            $byName->refund();
            throw new UserError($why, [], 403);
        }
        $byName->clear();
        setcookie(cookie_name('pbput_nosso'), '', cookie_options(1));
        return self::establish($row, 'password');
    }

    public static function logout(): void
    {
        session_boot();
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        // Otherwise the forum cookie would sign the user straight back in.
        setcookie(cookie_name('pbput_nosso'), '1', cookie_options());
    }

    private static function establish(array $row, string $via): array
    {
        $dirs = Storage::dirsForUser((int)$row['user_id'], (string)$row['username']);
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['user'] = [
            'id'         => (int)$row['user_id'],
            'name'       => (string)$row['username'],
            'founder'    => (int)$row['user_type'] === Phpbb::USER_FOUNDER,
            'lang'       => (string)$row['user_lang'],
            'dirs'       => $dirs,
            'dir'        => $dirs[0],
            'via'        => $via,
            'auth_at'    => time(),
            'checked_at' => time(),
        ];
        return $_SESSION['user'];
    }

    /** Switch the active space among the directories that belong to the user. */
    public static function selectDir(string $id): void
    {
        $dir = Storage::idToName($id);
        if (!in_array($dir, $_SESSION['user']['dirs'] ?? [], true)) {
            throw new UserError('err.unknown_folder');
        }
        $_SESSION['user']['dir'] = $dir;
    }
}
