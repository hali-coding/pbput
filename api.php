<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

security_headers();
// API responses are for this tool's own pages only.
header('Cross-Origin-Resource-Policy: same-origin');

$action = (string)($_GET['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/** Request body: JSON for normal calls, multipart for upload chunks. */
function input(): array
{
    static $in = null;
    if ($in === null) {
        $type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $in = stripos($type, 'application/json') === 0
            ? (json_decode((string)file_get_contents('php://input'), true) ?: [])
            : $_POST;
    }
    return $in;
}

function arg(string $key, string $default = ''): string
{
    $v = input()[$key] ?? $_GET[$key] ?? $default;
    return is_scalar($v) ? (string)$v : $default;
}

function quota_for(array $user): int
{
    $o = pbput_config('quota_overrides');
    return (int)($o[$user['id']] ?? pbput_config('quota_bytes'));
}

function chunk_size(): int
{
    $limits = array_filter([ini_bytes('upload_max_filesize'), ini_bytes('post_max_size')]);
    $limit = $limits ? min($limits) : PHP_INT_MAX;
    // Leave room for the multipart envelope and other fields.
    return max(256 * 1024, min((int)pbput_config('chunk_bytes'), $limit - 64 * 1024));
}

try {
    // Refuse requests started by other sites, including same-site ones from
    // the user-content host (SameSite cookies do not stop those). Browsers without
    // Sec-Fetch-* still need the CSRF token for every change.
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') {
        throw new UserError('err.cross_site', [], 403);
    }
    $mutating = ['login', 'logout', 'space', 'mkdir', 'rename', 'move', 'delete', 'chunk', 'abort', 'save-layout',
        'preview-layout'];
    if (in_array($action, $mutating, true) && !$isPost) {
        throw new UserError('err.method', [], 405);
    }
    if ($isPost) {
        session_boot();
        csrf_check();
    }

    if ($action === 'login') {
        $u = Auth::login(arg('username'), (string)(input()['password'] ?? ''));
        json_out(['ok' => true, 'user' => $u['name']]);
        exit;
    }

    $user = Auth::user();
    if ($user) {
        I18n::set($user['lang'] ?? '');
    }
    if (!$user) {
        throw new UserError('err.sign_in', [], 401);
    }

    if ($action === 'logout') {
        Auth::logout();
        json_out(['ok' => true]);
        exit;
    }
    if ($action === 'space') {
        Auth::selectDir(arg('dir'));
        $user = $_SESSION['user'];
    }

    // Everything below only reads the session; release its lock so parallel
    // chunk uploads are not serialised.
    session_write_close();
    $fs = Storage::forUser($user);

    switch ($action) {
        case 'me':
        case 'space':
            $quota = quota_for($user);
            json_out([
                'user'      => $user['name'],
                'dir'       => Storage::displayName($user['dir']),
                'dirs'      => array_map(static function (string $d) use ($user) {
                    return ['id' => Storage::nameToId($d), 'name' => Storage::displayName($d), 'current' => $d === $user['dir']];
                }, $user['dirs']),
                'publicUrl' => Storage::publicUrlFor($user['dir']),
                'quota'     => $quota,
                'used'      => $fs->usage(),
                'chunk'     => chunk_size(),
                'maxFile'   => (int)pbput_config('max_file_bytes'),
                'blocked'   => array_values(pbput_config('blocked_extensions')),
            ]);
            break;

        case 'list':
            json_out($fs->listDir(arg('path')));
            break;

        case 'usage':
            json_out(['used' => $fs->usage(), 'quota' => quota_for($user)]);
            break;

        case 'mkdir':
            json_out(['ok' => true, 'name' => $fs->mkdir(arg('path'), arg('name'))]);
            break;

        case 'rename':
            json_out(['ok' => true, 'name' => $fs->rename(arg('path'), arg('id'), arg('name'))]);
            break;

        case 'move':
            json_out(['ok' => true, 'count' => $fs->move(arg('path'), (array)(input()['ids'] ?? []), arg('dest'))]);
            break;

        case 'delete':
            json_out(['ok' => true, 'count' => $fs->delete(arg('path'), (array)(input()['ids'] ?? []))]);
            break;

        case 'chunk':
            $f = $_FILES['chunk'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new UserError('err.chunk_missing', ['code' => $f['error'] ?? '?']);
            }
            json_out($fs->receiveChunk(
                arg('path'),
                arg('name'),
                arg('id'),
                (int)arg('size'),
                (int)arg('offset'),
                $f['tmp_name'],
                quota_for($user)
            ));
            break;

        case 'abort':
            $fs->abort(arg('id'));
            json_out(['ok' => true]);
            break;

        case 'layout':
            json_out($fs->layout(arg('path')));
            break;

        case 'save-layout':
            $in = input();
            json_out(['ok' => true] + $fs->saveLayout(arg('path'), (string)($in['header'] ?? ''), (string)($in['footer'] ?? '')));
            break;

        case 'file-html':
            json_out($fs->htmlFile(arg('path'), arg('id')));
            break;

        case 'folder-preview':
            json_out($fs->folderPreview(arg('path')));
            break;

        case 'preview-layout':
            $in = input();
            json_out($fs->previewLayout(arg('path'), (string)($in['header'] ?? ''), (string)($in['footer'] ?? '')));
            break;

        default:
            throw new UserError('err.unknown_action', [], 404);
    }
} catch (UserError $e) {
    json_out(['error' => $e->message()] + $e->extra, $e->status);
} catch (Throwable $e) {
    error_log('pbput: ' . $e);
    json_out(['error' => t('err.server')], 500);
}
