<?php
declare(strict_types=1);

defined('PBPUT_ROOT') || exit;

/**
 * File operations confined to one user's directory.
 *
 * Paths from the client are lists of entry ids. An id is the entry name, or
 * "b64:<URL-safe base64>" for legacy names that are not valid UTF-8 (FTP-era Latin-1
 * names such as "Hornh\xF6gtalare"), so that those can still be opened,
 * renamed and deleted.
 */
final class Storage
{
    const HEADER = 'HEADER.html';
    const FOOTER = 'README.html';
    const TMP_PREFIX = '.pbput-';

    /** @var string */
    private $root;
    /** @var string */
    private $publicUrl;
    /** @var array */
    private $cfg;

    public function __construct(string $root, string $publicUrl, array $cfg)
    {
        $this->root = rtrim($root, '/');
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->cfg = $cfg;
    }

    public static function forUser(array $user): self
    {
        $root = self::resolveUserDir((string)$user['dir']);
        if ($root === null) {
            error_log("pbput: refused folder for user {$user['id']}: not a real directory directly under storage_root");
            throw new UserError('err.dir_missing', [], 500);
        }
        // The resolved path is the containment root for abs(), so swapping the
        // folder for a symlink later in the request cannot move it.
        return new self($root, self::publicUrlFor($user['dir']), pbput_config());
    }

    /**
     * Resolved path of a user directory, or null unless it is a real directory
     * (not a symlink) directly under storage_root. Applies to dir_overrides and
     * to folders remembered in the session, so neither can leave the user area.
     */
    public static function resolveUserDir(string $dir): ?string
    {
        $base = realpath(pbput_config('storage_root'));
        if ($base === false || $dir === '' || $dir[0] === '.' || strpbrk($dir, "/\\\0") !== false) {
            return null;
        }
        $path = $base . '/' . $dir;
        $real = realpath($path);
        if (is_link($path) || $real === false || !is_dir($real) || dirname($real) !== $base) {
            return null;
        }
        return $real;
    }

    public static function publicUrlFor(string $dir): string
    {
        return pbput_config('public_base_url') . '/' . rawurlencode($dir) . '/';
    }

    /* ------------------------------------------------------------------ */
    /* User → directory mapping                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Directories (names directly under storage_root) that belong to a user,
     * preferred one first. Matches FTP-era folders case-insensitively, so a
     * user "Alice" owns both "alice" and "ALICE".
     */
    public static function dirsForUser(int $uid, string $username): array
    {
        $cfg = pbput_config();
        $root = $cfg['storage_root'];
        if (isset($cfg['dir_overrides'][$uid])) {
            $dirs = array_values(array_filter((array)$cfg['dir_overrides'][$uid], static function ($d): bool {
                return is_string($d) && self::resolveUserDir($d) !== null;
            }));
            if (!$dirs) {
                error_log("pbput: dir_overrides for user $uid has no existing folder directly under storage_root");
                throw new UserError('err.no_dir', [], 403);
            }
            return $dirs;
        }
        $reserved = array_map('mb_strtolower', $cfg['reserved_dirs'] ?? []);

        $name = $cfg['dir_naming'] === 'username_clean' ? Phpbb::cleanUsername($username) : $username;
        $want = self::safeDirName($name);
        if ($want !== nfc(trim($name))) {
            // Sanitising can make two accounts collide ("a/b" and "a_b"), which
            // would hand one user the other's folder. Make such names unique.
            $want .= '-' . $uid;
        }
        $lower = mb_strtolower($want, 'UTF-8');

        $exact = null;
        $others = [];
        foreach (scandir($root) ?: [] as $d) {
            // Legacy Latin-1 names ("J\xF6rg") are compared in their UTF-8 form.
            if ($d[0] === '.' || mb_strtolower(self::displayName($d), 'UTF-8') !== $lower) {
                continue;
            }
            if (in_array($lower, $reserved, true) || !is_dir("$root/$d") || is_link("$root/$d")) {
                continue;
            }
            if ($d === $want) {
                $exact = $d;
            } else {
                $others[$d] = (int)@filemtime("$root/$d");
            }
        }
        arsort($others);
        $dirs = array_keys($others);
        if ($exact !== null) {
            array_unshift($dirs, $exact);
        }
        if ($dirs) {
            return $dirs;
        }

        if (in_array($lower, $reserved, true) || !$cfg['create_user_dirs']) {
            throw new UserError('err.no_dir', [], 403);
        }
        $path = "$root/$want";
        if (!@mkdir($path, $cfg['dir_mode']) && !is_dir($path)) {
            $why = error_get_last()['message'] ?? 'unknown error';
            error_log("pbput: could not create folder $path for user $uid: $why");
            throw new UserError('err.dir_create_failed', [], 500);
        }
        @chmod($path, $cfg['dir_mode']);
        return [$want];
    }

    private static function safeDirName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', nfc(trim($name)));
        $name = ltrim($name, '.');
        return $name === '' ? '_' : $name;
    }

    /* ------------------------------------------------------------------ */
    /* Paths & names                                                      */
    /* ------------------------------------------------------------------ */

    public static function idToName(string $id): string
    {
        if (strncmp($id, 'b64:', 4) === 0) {
            // URL-safe alphabet; "+" and "/" from older ids are accepted too.
            $raw = base64_decode(strtr(substr($id, 4), '-_', '+/'), true);
            if ($raw === false) {
                throw new UserError('err.invalid_path');
            }
            return $raw;
        }
        return $id;
    }

    public static function nameToId(string $name): string
    {
        // URL-safe base64: paths are "/"-joined ids, so an id must never contain "/".
        // Names that themselves start with "b64:" are encoded too, so they round-trip.
        if (preg_match('//u', $name) && strncmp($name, 'b64:', 4) !== 0) {
            return $name;
        }
        return 'b64:' . strtr(base64_encode($name), '+/', '-_');
    }

    public static function displayName(string $name): string
    {
        return preg_match('//u', $name) ? $name : mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
    }

    /** Validate an existing entry name taken from a path. */
    private static function checkSegment(string $seg): string
    {
        $seg = self::idToName($seg);
        if ($seg === '' || $seg === '.' || $seg === '..' || $seg[0] === '.'
            || strpbrk($seg, "/\\\0") !== false) {
            throw new UserError('err.invalid_path');
        }
        return $seg;
    }

    /** @param string|array $path "a/b" or ['a','b'] */
    private function segments($path): array
    {
        $parts = is_array($path) ? $path : explode('/', (string)$path);
        $out = [];
        foreach ($parts as $p) {
            $p = (string)$p;
            if ($p !== '') {
                $out[] = self::checkSegment($p);
            }
        }
        return $out;
    }

    /** Absolute path for an in-root path; refuses to traverse symlinks out of the root. */
    private function abs($path, bool $mustExist = true): string
    {
        $abs = $this->root;
        foreach ($this->segments($path) as $seg) {
            $abs .= '/' . $seg;
        }
        if ($mustExist) {
            $real = realpath($abs);
            $rootReal = realpath($this->root);
            if ($real === false) {
                throw new UserError('err.not_found', [], 404);
            }
            if ($real !== $rootReal && strpos($real, $rootReal . '/') !== 0) {
                throw new UserError('err.invalid_path');
            }
        }
        return $abs;
    }

    /** Validate a new name chosen by the user. */
    public function validName(string $name, bool $isFile): string
    {
        $name = nfc(trim($name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new UserError('err.name_empty');
        }
        if (mb_strlen($name, 'UTF-8') > 200 || !preg_match('//u', $name)) {
            throw new UserError('err.name_invalid');
        }
        if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)) {
            throw new UserError('err.name_chars');
        }
        if ($name[0] === '.') {
            throw new UserError('err.name_dot');
        }
        if (strncmp($name, 'b64:', 4) === 0) {
            // Reserved for encoded legacy ids (see nameToId()); such a name could
            // be decoded into a different entry further down a path.
            throw new UserError('err.name_b64');
        }
        if ($isFile) {
            // Check every extension: Apache would run "x.php.jpg" as PHP with some handlers.
            $parts = explode('.', mb_strtolower($name, 'UTF-8'));
            array_shift($parts);
            $bad = array_intersect($parts, $this->cfg['blocked_extensions']);
            if ($bad) {
                throw new UserError('err.ext_blocked', ['ext' => reset($bad)]);
            }
        }
        return $name;
    }

    private function url(array $segs): string
    {
        return $this->publicUrl . '/' . implode('/', array_map('rawurlencode', $segs));
    }

    /* ------------------------------------------------------------------ */
    /* Listing                                                            */
    /* ------------------------------------------------------------------ */

    public function listDir($path): array
    {
        $segs = $this->segments($path);
        $abs = $this->abs($segs);
        if (!is_dir($abs)) {
            throw new UserError('err.not_folder', [], 404);
        }
        $entries = [];
        foreach (scandir($abs) ?: [] as $name) {
            if ($name[0] === '.') {
                continue;
            }
            $p = "$abs/$name";
            $st = @lstat($p);
            if ($st === false) {
                continue;
            }
            $isLink = is_link($p);
            $isDir = is_dir($p);
            if ($isLink) {
                // Only show links that stay inside the user's space.
                $real = realpath($p);
                $rootReal = realpath($this->root);
                if ($real === false || strpos($real, $rootReal . '/') !== 0) {
                    continue;
                }
            }
            $entries[] = [
                'id'       => self::nameToId($name),
                'name'     => self::displayName($name),
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? null : (int)$st['size'],
                'mtime'    => (int)$st['mtime'],
                'url'      => $this->url(array_merge($segs, [$name])) . ($isDir ? '/' : ''),
                'badName'  => !preg_match('//u', $name),
                'special'  => $isDir ? null : ($name === self::HEADER ? 'header' : ($name === self::FOOTER ? 'footer' : null)),
            ];
        }
        usort($entries, static function ($a, $b) {
            return [$a['type'] !== 'dir', mb_strtolower($a['name'])] <=> [$b['type'] !== 'dir', mb_strtolower($b['name'])];
        });

        $crumbs = [];
        $acc = [];
        foreach ($segs as $s) {
            $acc[] = self::nameToId($s);
            $crumbs[] = ['name' => self::displayName($s), 'path' => implode('/', $acc)];
        }
        return [
            'path'     => implode('/', array_map([self::class, 'nameToId'], $segs)),
            'crumbs'   => $crumbs,
            'url'      => $segs ? $this->url($segs) . '/' : $this->publicUrl . '/',
            'writable' => is_writable($abs),
            'entries'  => $entries,
        ];
    }

    public function usage(): int
    {
        $total = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $total += $f->getSize();
            }
        }
        return $total;
    }

    /* ------------------------------------------------------------------ */
    /* Mutations                                                          */
    /* ------------------------------------------------------------------ */

    public function mkdir($path, string $name): string
    {
        $name = $this->validName($name, false);
        $target = $this->abs($path) . '/' . $name;
        if (file_exists($target)) {
            throw new UserError('err.exists', [], 409);
        }
        if (!@mkdir($target, $this->cfg['dir_mode'])) {
            throw $this->permissionError();
        }
        @chmod($target, $this->cfg['dir_mode']);
        return $name;
    }

    public function rename($path, string $id, string $newName): string
    {
        $from = $this->abs(array_merge($this->segments($path), [$id]));
        $newName = $this->validName($newName, !is_dir($from));
        $to = dirname($from) . '/' . $newName;
        if ($to === $from) {
            return $newName;
        }
        // Allow pure case changes on case-insensitive filesystems, where $to is
        // $from itself. On case-sensitive ones "foo" and "FOO" are two entries,
        // and rename() would silently replace the other one.
        if ((file_exists($to) || is_link($to)) && !self::sameEntry($from, $to)) {
            throw new UserError('err.exists', [], 409);
        }
        if (!@rename($from, $to)) {
            throw $this->permissionError();
        }
        return $newName;
    }

    /** True if both paths are the same directory entry (not following symlinks). */
    private static function sameEntry(string $a, string $b): bool
    {
        $sa = @lstat($a);
        $sb = @lstat($b);
        return $sa !== false && $sb !== false && $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
    }

    /** Move entries into another folder of the same space. */
    public function move($path, array $ids, $destPath): int
    {
        $dest = $this->abs($destPath);
        if (!is_dir($dest)) {
            throw new UserError('err.target_not_folder');
        }
        $destReal = realpath($dest);
        $n = 0;
        foreach ($ids as $id) {
            $from = $this->abs(array_merge($this->segments($path), [(string)$id]));
            $fromReal = realpath($from);
            if ($fromReal === $destReal || strpos($destReal . '/', $fromReal . '/') === 0) {
                throw new UserError('err.move_into_self');
            }
            $to = $dest . '/' . basename($from);
            if (file_exists($to)) {
                throw new UserError('err.exists_in_target', ['name' => self::displayName(basename($from))], 409);
            }
            if (!@rename($from, $to)) {
                throw $this->permissionError();
            }
            $n++;
        }
        return $n;
    }

    public function delete($path, array $ids): int
    {
        $base = $this->segments($path);
        $n = 0;
        foreach ($ids as $id) {
            $target = $this->abs(array_merge($base, [(string)$id]));
            if ($target === $this->root) {
                throw new UserError('err.invalid_path');
            }
            $this->removeTree($target);
            $n++;
        }
        return $n;
    }

    private function removeTree(string $p): void
    {
        if (is_link($p) || !is_dir($p)) {
            if (!@unlink($p)) {
                throw $this->permissionError();
            }
            return;
        }
        foreach (scandir($p) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree("$p/$name");
            }
        }
        if (!@rmdir($p)) {
            throw $this->permissionError();
        }
    }

    private function permissionError(): UserError
    {
        $e = error_get_last();
        if ($e && stripos($e['message'], 'permission') !== false) {
            return new UserError('err.permission', [], 403);
        }
        return new UserError('err.op_failed', [], 500);
    }

    /* ------------------------------------------------------------------ */
    /* Chunked uploads                                                    */
    /* ------------------------------------------------------------------ */

    private function partPaths(string $uploadId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new UserError('err.upload_id');
        }
        // Kept inside the user's space so the final rename is atomic; dotfiles
        // are hidden by Apache (IndexIgnore .??*) and by this tool.
        $base = $this->root . '/' . self::TMP_PREFIX . $uploadId;
        return [$base . '.part', $base . '.json'];
    }

    /**
     * Append one chunk. Returns ['offset' => bytes stored] while incomplete,
     * or ['done' => true, 'name' => final name] once the last chunk is in.
     *
     * @param string $relPath file name, optionally with sub folders ("pics/a.jpg") for folder drops
     */
    public function receiveChunk($dir, string $relPath, string $uploadId, int $size, int $offset, string $chunkFile, int $quota): array
    {
        [$part, $metaFile] = $this->partPaths($uploadId);
        $relSegs = array_values(array_filter(explode('/', str_replace('\\', '/', $relPath)), 'strlen'));
        if (!$relSegs || count($relSegs) > 32) {
            throw new UserError('err.file_name');
        }
        $fileName = $this->validName(array_pop($relSegs), true);
        foreach ($relSegs as &$s) {
            $s = $this->validName($s, false);
        }
        unset($s);
        $dirSegs = $this->segments($dir);

        if ($offset === 0) {
            if ($size > $this->cfg['max_file_bytes']) {
                throw new UserError('err.too_large', ['limit' => human_bytes($this->cfg['max_file_bytes'])], 413);
            }
            if ($quota > 0 && $this->usage() + $size > $quota) {
                throw new UserError('err.quota', [], 413);
            }
            if (!is_writable($this->root)) {
                throw new UserError('err.root_not_writable', [], 403);
            }
            $this->abs($dirSegs); // target folder must exist
            $meta = ['size' => $size, 'dir' => $dirSegs, 'rel' => $relSegs, 'name' => $fileName, 'started' => time()];
            if (file_put_contents($metaFile, json_encode($meta)) === false || file_put_contents($part, '') === false) {
                throw new UserError('err.upload_start', [], 500);
            }
            $this->gcParts();
        } else {
            $meta = json_decode((string)@file_get_contents($metaFile), true);
            if (!is_array($meta) || $meta['size'] !== $size || !is_file($part)) {
                throw new UserError('err.upload_expired', [], 410);
            }
        }

        $fh = fopen($part, 'r+b');
        if (!$fh || !flock($fh, LOCK_EX)) {
            throw new UserError('err.upload_write', [], 500);
        }
        try {
            clearstatcache(true, $part);
            $have = filesize($part);
            if ($have !== $offset) {
                // Lets the client resume from what the server actually has.
                throw new UserError('err.offset', [], 409, ['offset' => $have]);
            }
            $in = fopen($chunkFile, 'rb');
            fseek($fh, $offset);
            $written = stream_copy_to_stream($in, $fh);
            fclose($in);
            fflush($fh);
            $have = $offset + (int)$written;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        if ($have > $size) {
            $this->abort($uploadId);
            throw new UserError('err.too_much_data');
        }
        if ($have < $size) {
            touch($metaFile);
            return ['offset' => $have];
        }
        return ['done' => true] + $this->finalize($part, $metaFile, $meta, $quota);
    }

    private function finalize(string $part, string $metaFile, array $meta, int $quota): array
    {
        $dir = $this->abs($meta['dir']);
        foreach ($meta['rel'] as $seg) {
            $dir .= '/' . $seg;
            if (!is_dir($dir)) {
                if (file_exists($dir) || !@mkdir($dir, $this->cfg['dir_mode'])) {
                    @unlink($part);
                    @unlink($metaFile);
                    throw new UserError('err.mkdir_failed', ['name' => $seg], 500);
                }
                @chmod($dir, $this->cfg['dir_mode']);
            }
        }
        if ($quota > 0 && $this->usage() > $quota) {
            @unlink($part);
            @unlink($metaFile);
            throw new UserError('err.quota', [], 413);
        }

        $name = $meta['name'];
        $dot = strrpos($name, '.');
        $stem = $dot > 0 ? substr($name, 0, $dot) : $name;
        $ext = $dot > 0 ? substr($name, $dot) : '';
        for ($i = 2; file_exists("$dir/$name"); $i++) {
            $name = "$stem ($i)$ext";
        }
        if (!@rename($part, "$dir/$name")) {
            @unlink($part);
            @unlink($metaFile);
            throw $this->permissionError();
        }
        @chmod("$dir/$name", $this->cfg['file_mode']);
        @unlink($metaFile);
        $segs = array_merge($meta['dir'], $meta['rel'], [$name]);
        return ['name' => $name, 'url' => $this->url($segs)];
    }

    public function abort(string $uploadId): void
    {
        foreach ($this->partPaths($uploadId) as $f) {
            @unlink($f);
        }
    }

    /** Drop partial uploads nobody touched for a day. */
    private function gcParts(): void
    {
        foreach (glob($this->root . '/' . self::TMP_PREFIX . '*.json') ?: [] as $meta) {
            if (@filemtime($meta) < time() - 86400) {
                @unlink($meta);
                @unlink(substr($meta, 0, -5) . '.part');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Header / footer (Apache mod_autoindex HeaderName / ReadmeName)     */
    /* ------------------------------------------------------------------ */

    public function layout($path): array
    {
        $abs = $this->abs($path);
        return [
            'header' => $this->readLayoutFile($abs, self::HEADER),
            'footer' => $this->readLayoutFile($abs, self::FOOTER),
            'hasIndex' => is_file("$abs/index.html") || is_file("$abs/index.htm"),
        ];
    }

    /**
     * HEADER.html / README.html in a folder from abs(), or '' if it is missing
     * or resolves outside the root (e.g. an FTP-era symlink to a server file).
     * Saving replaces such a link itself, never its target.
     */
    private function readLayoutFile(string $dir, string $name): string
    {
        $real = realpath("$dir/$name");
        $rootReal = realpath($this->root);
        if ($real === false || !is_file($real) || strpos($real, $rootReal . '/') !== 0) {
            return '';
        }
        return (string)@file_get_contents($real);
    }

    /**
     * Clean and store HEADER.html / README.html. Returns what was actually
     * saved and what the cleaner removed, so the editor can show it.
     */
    public function saveLayout($path, string $header, string $footer): array
    {
        $abs = $this->abs($path);
        $max = $this->cfg['max_layout_bytes'];
        [$header, $footer, $removed] = $this->cleanLayout($header, $footer);
        foreach ([self::HEADER => $header, self::FOOTER => $footer] as $file => $html) {
            if (strlen($html) > $max) {
                throw new UserError('err.layout_too_large', ['limit' => human_bytes($max)], 413);
            }
            $target = "$abs/$file";
            if (trim($html) === '') {
                if (file_exists($target) && !@unlink($target)) {
                    throw $this->permissionError();
                }
                continue;
            }
            $this->writeAtomic($target, $html);
        }
        return ['header' => $header, 'footer' => $footer, 'removed' => $removed];
    }

    private function cleanLayout(string $header, string $footer): array
    {
        [$h, $r1] = Sanitizer::clean($header);
        [$f, $r2] = Sanitizer::clean($footer);
        return [$h, $f, array_values(array_unique(array_merge($r1, $r2)))];
    }

    /** What visitors currently get for this folder (the saved Page design). */
    public function folderPreview($path): array
    {
        $dir = $this->abs($path);
        return $this->previewLayout(
            $path,
            $this->readLayoutFile($dir, self::HEADER),
            $this->readLayoutFile($dir, self::FOOTER)
        );
    }

    /** What saving would publish, without writing anything: the cleaned header and footer. */
    public function previewLayout($path, string $header, string $footer): array
    {
        [$h, $f, $removed] = $this->cleanLayout($header, $footer);
        return ['header' => $h, 'footer' => $f, 'removed' => $removed];
    }

    /** Write via a temp file in the same directory, so readers never see half a file. */
    private function writeAtomic(string $target, string $data): void
    {
        $tmp = dirname($target) . '/' . self::TMP_PREFIX . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $data) === false) {
            throw $this->permissionError();
        }
        @chmod($tmp, $this->cfg['file_mode']);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw $this->permissionError();
        }
    }

    const HTML_TYPES = ['html', 'htm', 'xhtml', 'xht'];

    /**
     * An HTML file from the user's folder, cleaned for previewing inside the
     * tool (only ever shown in sandboxed frames, never served as a page).
     */
    public function htmlFile($path, string $id): array
    {
        $file = $this->abs(array_merge($this->segments($path), [$id]));
        $dot = strrpos($file, '.');
        if (!is_file($file) || $dot === false || !in_array(strtolower(substr($file, $dot + 1)), self::HTML_TYPES, true)) {
            throw new UserError('err.not_html', [], 404);
        }
        if (filesize($file) > 1024 * 1024) {
            throw new UserError('err.html_too_large', [], 413);
        }
        $html = (string)file_get_contents($file);
        if (!preg_match('//u', $html)) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252'); // FTP-era Latin-1 pages
        }
        [$clean, $removed] = Sanitizer::clean($html);
        return ['html' => $clean, 'removed' => $removed];
    }
}
