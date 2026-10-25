<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

security_headers();
header('Cache-Control: no-store');

$cfg = pbput_config();
$error = null;
try {
    $user = Auth::user();
} catch (UserError $e) {
    $user = null;
    $error = $e;
} catch (PDOException $e) {
    error_log('pbput: ' . $e);
    $user = null;
    $error = new UserError('err.db_down', [], 503);
} catch (Throwable $e) {
    error_log('pbput: ' . $e);
    $user = null;
    $error = new UserError('err.server', [], 500);
}
if ($user) {
    I18n::set($user['lang'] ?? '');
    I18n::remember($user['lang'] ?? '');
}
$error = $error ? $error->message() : null;

$boot = [
    'csrf'     => csrf_token(),
    'authed'   => (bool)$user,
    'user'     => $user['name'] ?? null,
    'site'     => $cfg['site_name'],
    'forumUrl' => $cfg['forum_url'],
    'error'    => $error,
    'lang'     => I18n::lang(),
    'strings'  => I18n::strings(),
];
$v = PBPUT_VERSION;
$mono = static function (string $s): string {
    return '<span class="mono">' . h($s) . '</span>';
};
?><!doctype html>
<html lang="<?= h(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= h(t('page.title')) ?> · <?= h($cfg['site_name']) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css?v=<?= h($v) ?>">
<script src="assets/theme.js?v=<?= h($v) ?>"></script>
</head>
<body>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<?php if (!$user): ?>
<main class="login-wrap">
  <form class="login card" id="login-form" autocomplete="on">
    <div class="brand brand-lg"><?php include __DIR__ . '/assets/logo.svg'; ?><span><?= h($cfg['site_name']) ?> <b><?= h(t('brand.files')) ?></b></span></div>
    <p class="muted"><?= th('login.intro', ['host' => $mono(preg_replace('#^https?://#', '', $cfg['public_base_url']))]) ?></p>
    <div class="alert" id="login-error" <?= $error ? '' : 'hidden' ?>><?= h((string)$error) ?></div>
    <label class="field"><span><?= h(t('login.username')) ?></span>
      <input name="username" autocomplete="username" required autofocus></label>
    <label class="field"><span><?= h(t('login.password')) ?></span>
      <input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary btn-block" type="submit" id="login-btn"><?= h(t('login.submit')) ?></button>
    <p class="muted small"><?= th('login.sso_hint', ['forum' => '<a href="' . h($cfg['forum_url']) . '">' . h(t('login.forum_link')) . '</a>']) ?></p>
    <button type="button" class="icon-btn theme-toggle floating" data-theme-toggle title="<?= h(t('theme.toggle')) ?>" aria-label="<?= h(t('theme.toggle')) ?>"></button>
  </form>
</main>
<?php else: ?>
<div class="app" id="app">
  <header class="topbar">
    <div class="brand"><?php include __DIR__ . '/assets/logo.svg'; ?><span class="brand-site"><?= h($cfg['site_name']) ?> <b><?= h(t('brand.files')) ?></b></span><span class="brand-user" title="<?= h(t('brand.signed_in_as', ['name' => $user['name']])) ?>"><span class="brand-sep" aria-hidden="true">/</span><?= h($user['name']) ?></span></div>
    <nav class="tabs" role="tablist">
      <button class="tab active" data-view="files" role="tab" aria-selected="true"><svg><use href="#i-folder"/></svg><span><?= h(t('tab.files')) ?></span></button>
      <button class="tab" data-view="design" role="tab" aria-selected="false"><svg><use href="#i-layout"/></svg><span><?= h(t('tab.design')) ?></span></button>
    </nav>
    <div class="topbar-right">
      <div class="usage" id="usage" title="<?= h(t('usage.title')) ?>"><div class="usage-bar"><i></i></div><span></span></div>
      <button class="icon-btn theme-toggle" data-theme-toggle title="<?= h(t('theme.toggle')) ?>" aria-label="<?= h(t('theme.toggle')) ?>"></button>
      <div class="menu-wrap">
        <button class="user-btn" id="user-btn" aria-haspopup="true"><span class="avatar"></span><span class="uname"></span></button>
        <div class="menu" id="user-menu" hidden>
          <div class="menu-head muted small"><?= h(t('menu.public')) ?></div>
          <a class="menu-item mono small" id="public-link" target="_blank" rel="noopener"></a>
          <div id="space-list"></div>
          <hr>
          <a class="menu-item" href="<?= h($cfg['forum_url']) ?>"><?= h(t('menu.forum')) ?></a>
          <button class="menu-item" id="logout-btn"><?= h(t('menu.logout')) ?></button>
        </div>
      </div>
    </div>
  </header>

  <main class="content">
    <!-- Files -->
    <section class="view" id="view-files">
      <div class="toolbar">
        <nav class="crumbs" id="crumbs"></nav>
        <div class="toolbar-actions">
          <div class="search"><input id="filter" type="search" placeholder="<?= h(t('files.filter')) ?>" aria-label="<?= h(t('files.filter_aria')) ?>"></div>
          <button class="btn" id="new-folder-btn"><svg><use href="#i-folder-plus"/></svg><span><?= h(t('files.new_folder')) ?></span></button>
          <label class="btn btn-primary"><svg><use href="#i-upload"/></svg><span><?= h(t('files.upload')) ?></span>
            <input type="file" id="file-input" multiple hidden></label>
        </div>
      </div>

      <div class="selection-bar" id="selection-bar" hidden>
        <span id="selection-count"></span>
        <button class="btn btn-sm" id="sel-bbcode"><?= h(t('files.copy_bbcode')) ?></button>
        <button class="btn btn-sm" id="sel-move"><?= h(t('files.move_to')) ?></button>
        <button class="btn btn-sm btn-danger" id="sel-delete"><?= h(t('files.delete')) ?></button>
        <button class="btn btn-sm btn-ghost" id="sel-clear"><?= h(t('files.clear')) ?></button>
      </div>

      <!-- What visitors get at this folder's public address. -->
      <div class="page-bar" id="page-bar">
        <span class="page-where">
          <svg><use href="#i-globe"/></svg>
          <span class="muted small"><?= h(t('page.public')) ?></span>
          <a id="page-url" class="mono" target="_blank" rel="noopener"></a>
          <button class="icon-btn" id="page-copy" title="<?= h(t('row.copy_link')) ?>" aria-label="<?= h(t('row.copy_link')) ?>"><svg><use href="#i-link"/></svg></button>
        </span>
        <span class="chip" id="page-state"></span>
        <span class="page-actions">
          <button class="icon-btn" id="preview-toggle" title="<?= h(t('pv.toggle')) ?>" aria-label="<?= h(t('pv.toggle')) ?>" aria-pressed="false"><svg><use href="#i-eye"/></svg></button>
        </span>
      </div>

      <div class="browse" id="browse">
      <div class="card file-card">
        <div class="notice" id="readonly-notice" hidden><?= h(t('files.readonly')) ?></div>
        <table class="files" id="files">
          <thead><tr>
            <th class="col-check"><input type="checkbox" id="check-all" aria-label="<?= h(t('files.select_all')) ?>"></th>
            <th class="col-name" data-sort="name"><?= h(t('col.name')) ?></th>
            <th class="col-size" data-sort="size"><?= h(t('col.size')) ?></th>
            <th class="col-date" data-sort="mtime"><?= h(t('col.modified')) ?></th>
            <th class="col-actions"></th>
          </tr></thead>
          <tbody id="file-rows"></tbody>
        </table>
        <div class="empty" id="empty" hidden>
          <svg class="empty-ico"><use href="#i-cloud"/></svg>
          <h3><?= h(t('empty.title')) ?></h3>
          <p class="muted"><?= h(t('empty.text')) ?></p>
        </div>
        <div class="loading" id="loading" hidden><span class="spinner"></span></div>
      </div>

      <!-- Preview: the folder's public page, or the file that was clicked. -->
      <aside class="card pv" id="pv" hidden>
        <div class="pv-head">
          <div class="pv-titles"><b id="pv-title"></b><span class="muted small" id="pv-sub"></span></div>
          <button class="icon-btn" id="pv-pop" title="<?= h(t('pv.popout')) ?>" aria-label="<?= h(t('pv.popout')) ?>" hidden><svg><use href="#i-popout"/></svg></button>
          <a class="icon-btn" id="pv-open" target="_blank" rel="noopener" title="<?= h(t('pv.open')) ?>" aria-label="<?= h(t('pv.open')) ?>"><svg><use href="#i-external"/></svg></a>
          <button class="icon-btn" id="pv-back" title="<?= h(t('pv.back')) ?>" aria-label="<?= h(t('pv.back')) ?>" hidden><svg><use href="#i-x"/></svg></button>
        </div>
        <div class="pv-page" id="pv-page"><iframe id="pv-frame" sandbox="" title="<?= h(t('pv.title')) ?>"></iframe></div>
        <div class="pv-file" id="pv-file" hidden></div>
      </aside>
      </div>
      <p class="muted small hint"><?= h(t('files.hint')) ?></p>
    </section>

    <!-- Header / footer -->
    <section class="view" id="view-design" hidden>
      <div class="toolbar">
        <div>
          <h2 class="h2"><?= h(t('design.title')) ?></h2>
          <p class="muted small"><?= th('design.intro', ['url' => '<a id="design-url" target="_blank" rel="noopener" class="mono"></a>']) ?></p>
        </div>
        <div class="toolbar-actions">
          <div class="seg" id="design-mode" role="radiogroup" aria-label="<?= h(t('design.mode_label')) ?>">
            <button type="button" role="radio" data-mode="visual"><?= h(t('design.mode_visual')) ?></button>
            <button type="button" role="radio" data-mode="code"><?= h(t('design.mode_code')) ?></button>
          </div>
          <span class="dirty muted small" id="design-dirty" hidden><?= h(t('design.unsaved')) ?></span>
          <button class="btn btn-danger" id="design-reset" title="<?= h(t('design.reset_hint')) ?>"><?= h(t('design.reset')) ?></button>
          <button class="btn" id="design-revert"><?= h(t('design.revert')) ?></button>
          <button class="btn btn-primary" id="design-save"><?= h(t('design.save')) ?></button>
        </div>
      </div>
      <div class="notice" id="index-notice" hidden><?= th('design.has_index', ['file' => $mono('index.html')]) ?></div>
      <div class="design-grid">
        <div class="editors">
          <?php foreach (['header' => 'HEADER.html', 'footer' => 'README.html'] as $part => $file): ?>
          <div class="card editor" data-part="<?= $part ?>">
            <div class="editor-head"><b><?= h(t('design.' . $part)) ?></b>
              <span class="editor-head-right">
                <?php if ($part === 'header'): ?>
                <span class="bg-pick unset">
                  <label class="bg-label small" title="<?= h(t('design.bg_title')) ?>">
                    <input type="color" id="bg-color" value="#1e1e1e" aria-label="<?= h(t('design.bg')) ?>"><?= h(t('design.bg')) ?></label>
                  <button type="button" class="icon-btn bg-clear" id="bg-clear" title="<?= h(t('design.bg_clear')) ?>" aria-label="<?= h(t('design.bg_clear')) ?>" hidden><svg><use href="#i-x"/></svg></button>
                </span>
                <?php endif; ?>
                <span class="muted small mono"><?= $file ?></span>
                <button type="button" class="icon-btn pop-btn" data-pop="<?= $part ?>" title="<?= h(t('pv.popout')) ?>" aria-label="<?= h(t('pv.popout')) ?>"><svg><use href="#i-popout"/></svg></button>
              </span>
            </div>
            <div class="rte" hidden>
              <div class="rte-toolbar" role="toolbar" aria-label="<?= h(t('design.' . $part)) ?>"></div>
              <div class="rte-note notice" hidden><?= h(t('rte.code_note')) ?></div>
              <div class="rte-frame-wrap">
                <!-- No allow-scripts: the user's markup is rendered, never executed. -->
                <iframe class="rte-frame" sandbox="allow-same-origin" title="<?= h(t('design.' . $part)) ?>"></iframe>
              </div>
            </div>
            <textarea id="ed-<?= $part ?>" spellcheck="false" placeholder="<?= h(t('design.' . $part . '_placeholder')) ?>"></textarea>
          </div>
          <?php endforeach; ?>
          <p class="muted small" data-help="code"><?= th('design.help', [
              'html'   => $mono('<html>'),
              'body'   => $mono('<body>'),
              'style'  => $mono('<style>'),
              'folder' => '<b class="design-folder"></b>',
          ]) ?></p>
          <p class="muted small" data-help="visual" hidden><?= th('design.help_visual', ['folder' => '<b class="design-folder"></b>']) ?></p>
        </div>
        <div class="card preview">
          <div class="editor-head"><b><?= h(t('design.preview')) ?></b>
            <span class="editor-head-right"><span class="muted small"><?= h(t('design.preview_note')) ?></span>
              <button type="button" class="icon-btn pop-btn" id="design-pop" title="<?= h(t('pv.popout')) ?>" aria-label="<?= h(t('pv.popout')) ?>"><svg><use href="#i-popout"/></svg></button></span></div>
          <iframe id="preview" sandbox="" title="<?= h(t('design.preview')) ?>"></iframe>
        </div>
      </div>
    </section>
  </main>

  <div class="dropzone" id="dropzone" hidden><div><svg><use href="#i-upload"/></svg><p><?= th('drop.into', ['folder' => '<b id="drop-target"></b>']) ?></p></div></div>

  <aside class="uploads card" id="uploads" hidden>
    <div class="uploads-head">
      <b id="uploads-title"><?= h(t('up.title')) ?></b>
      <div>
        <button class="icon-btn" id="uploads-min" title="<?= h(t('up.minimise')) ?>"><svg><use href="#i-chevron"/></svg></button>
        <button class="icon-btn" id="uploads-close" title="<?= h(t('up.clear')) ?>"><svg><use href="#i-x"/></svg></button>
      </div>
    </div>
    <ul class="uploads-list" id="uploads-list"></ul>
  </aside>
</div>
<?php endif; ?>

<div class="toasts" id="toasts" aria-live="polite"></div>
<dialog class="modal card" id="modal">
  <form method="dialog" id="modal-form">
    <h3 id="modal-title"></h3>
    <p class="muted" id="modal-text"></p>
    <div id="modal-body"></div>
    <div class="alert" id="modal-error" role="alert" hidden></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" value="cancel" type="button" id="modal-cancel"><?= h(t('dlg.cancel')) ?></button>
      <button class="btn btn-primary" value="ok" id="modal-ok"><?= h(t('dlg.ok')) ?></button>
    </div>
  </form>
</dialog>

<?php include __DIR__ . '/assets/icons.svg'; ?>
<script src="assets/app.js?v=<?= h($v) ?>"></script>
</body>
</html>
