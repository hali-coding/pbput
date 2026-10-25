/* pbput – file manager UI. Plain JS, no build step. */
(function () {
  'use strict';

  const boot = JSON.parse(document.getElementById('boot').textContent);

  /** Translate: t('key', {n: 2, name: 'x'}). Plural forms live under key.one / key.other. */
  const STR = boot.strings || {};
  const t = (key, args) => {
    if (args && 'n' in args) {
      const k = key + (args.n === 1 ? '.one' : '.other');
      if (k in STR) key = k;
    }
    const s = STR[key] ?? key;
    return args ? s.replace(/\{(\w+)\}/g, (m, k) => (k in args ? String(args[k]) : m)) : s;
  };
  const LOCALE = STR._locale || boot.lang || undefined;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const el = (tag, attrs, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v == null || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
      else if (k === 'html') n.innerHTML = v;
      else n.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid != null) n.append(kid.nodeType ? kid : String(kid));
    return n;
  };
  const icon = (id, cls) => {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    if (cls) s.setAttribute('class', cls);
    const u = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    u.setAttribute('href', '#i-' + id);
    s.append(u);
    return s;
  };

  /* ---------------------------------------------------------------- */
  /* Theme                                                            */
  /* ---------------------------------------------------------------- */

  const THEMES = ['auto', 'light', 'dark'];
  const store = {
    get(k, d) { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };
  function applyTheme(mode) {
    if (mode === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', mode);
    for (const b of $$('[data-theme-toggle]')) {
      b.replaceChildren(icon(mode === 'auto' ? 'auto' : mode === 'light' ? 'sun' : 'moon'));
      b.title = t('theme.title', { mode: t('theme.' + mode) });
    }
  }
  let theme = store.get('pbput-theme', 'auto');
  applyTheme(theme);
  document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-theme-toggle]')) return;
    theme = THEMES[(THEMES.indexOf(theme) + 1) % THEMES.length];
    store.set('pbput-theme', theme);
    applyTheme(theme);
  });

  /* ---------------------------------------------------------------- */
  /* API                                                              */
  /* ---------------------------------------------------------------- */

  class ApiError extends Error {
    constructor(msg, status, data) { super(msg); this.status = status; this.data = data; }
  }

  async function api(action, body, params) {
    const qs = new URLSearchParams(Object.assign({ a: action }, params || {}));
    const opt = { method: body ? 'POST' : 'GET', headers: { 'X-CSRF-Token': boot.csrf }, credentials: 'same-origin' };
    if (body) {
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(body);
    }
    let res, data;
    try {
      res = await fetch('api.php?' + qs, opt);
      data = await res.json();
    } catch (e) {
      throw new ApiError(t('err.network'), 0, {});
    }
    if (res.status === 401 && action !== 'login') {
      location.reload();
      throw new ApiError(data.error, 401, data);
    }
    if (!res.ok) throw new ApiError(data.error || t('err.request'), res.status, data);
    return data;
  }

  /* ---------------------------------------------------------------- */
  /* Toasts & modals                                                  */
  /* ---------------------------------------------------------------- */

  function toast(msg, kind) {
    const n = el('div', { class: 'toast ' + (kind || '') }, icon(kind === 'error' ? 'alert' : 'check'), msg);
    $('#toasts').append(n);
    setTimeout(() => { n.style.transition = 'opacity .3s'; n.style.opacity = '0'; }, kind === 'error' ? 5200 : 2600);
    setTimeout(() => n.remove(), kind === 'error' ? 5600 : 3000);
  }
  const fail = (e) => toast(e.message || String(e), 'error');

  /** Settles the dialog currently showing, so a dialog opened from another starts clean. */
  let settleActive = null;

  /** Generic modal. Resolves with the value of onOk(), or null when cancelled or replaced. */
  function modal({ title, text, body, ok = t('dlg.ok'), danger = false, onOk, focus }) {
    if (settleActive) settleActive(null, false);
    const dlg = $('#modal');
    $('#modal-title').textContent = title;
    $('#modal-text').textContent = text || '';
    $('#modal-text').hidden = !text;
    $('#modal-body').replaceChildren(...(body ? [body] : []));
    // Errors go inside the dialog: toasts would sit underneath its backdrop.
    const errBox = $('#modal-error');
    errBox.hidden = true;
    const okBtn = $('#modal-ok');
    okBtn.textContent = ok;
    okBtn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    return new Promise((resolve) => {
      let settled = false;
      const settle = (v, close) => {
        if (settled) return; // e.g. replaced by another dialog while onOk was running
        settled = true;
        $('#modal-form').removeEventListener('submit', submit);
        $('#modal-cancel').removeEventListener('click', cancel);
        dlg.removeEventListener('close', onClose);
        if (settleActive === settle) settleActive = null;
        if (close && dlg.open) dlg.close();
        resolve(v);
      };
      const submit = async (e) => {
        e.preventDefault();
        try {
          okBtn.disabled = true;
          settle(onOk ? await onOk() : true, true);
        } catch (err) {
          errBox.textContent = err.message || String(err);
          errBox.hidden = false;
        } finally {
          okBtn.disabled = false;
        }
      };
      const cancel = () => settle(null, true);
      // "close" is delivered asynchronously, so a stale one can arrive after the
      // next dialog is already showing: only a dialog that is really closed counts.
      const onClose = () => { if (!dlg.open) settle(null, false); };
      settleActive = settle;
      $('#modal-form').addEventListener('submit', submit);
      $('#modal-cancel').addEventListener('click', cancel);
      dlg.addEventListener('close', onClose);
      dlg.showModal();
      if (focus) { focus.focus(); if (focus.select) focus.select(); }
    });
  }

  function promptName(title, value, ok, action) {
    const input = el('input', { value: value || '', autocomplete: 'off', spellcheck: 'false' });
    const p = modal({ title, body: input, ok, focus: input, onOk: () => action(input.value) });
    // Select the stem only, like a desktop file manager.
    const dot = (value || '').lastIndexOf('.');
    if (dot > 0) input.setSelectionRange(0, dot);
    return p;
  }

  /* ---------------------------------------------------------------- */
  /* Login page                                                       */
  /* ---------------------------------------------------------------- */

  if (!boot.authed) {
    const form = $('#login-form');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('#login-btn');
      const err = $('#login-error');
      btn.disabled = true;
      btn.textContent = t('login.busy');
      err.hidden = true;
      try {
        await api('login', { username: form.username.value, password: form.password.value });
        location.reload();
      } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
        btn.disabled = false;
        btn.textContent = t('login.submit');
        form.password.select();
      }
    });
    return;
  }

  /* ---------------------------------------------------------------- */
  /* Helpers                                                          */
  /* ---------------------------------------------------------------- */

  const numFmt = [0, 1].map((d) => new Intl.NumberFormat(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d }));
  const fmtBytes = (b) => {
    if (b == null) return '';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? numFmt[b < 10 ? 1 : 0].format(b) : b) + '\u00a0' + u[i];
  };
  const dateFmt = new Intl.DateTimeFormat(LOCALE, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  const fmtDate = (sec) => dateFmt.format(new Date(sec * 1000));
  const ext = (n) => { const i = n.lastIndexOf('.'); return i > 0 ? n.slice(i + 1).toLowerCase() : ''; };
  const KINDS = {
    image: 'jpg jpeg png gif webp avif svg bmp ico heic tif tiff',
    audio: 'mp3 wav flac ogg oga m4a aac opus aif aiff wma',
    video: 'mp4 m4v mov webm mkv avi wmv mpg mpeg',
    archive: 'zip rar 7z tar gz tgz bz2 xz',
    doc: 'pdf doc docx odt rtf txt md xls xlsx ods csv ppt pptx odp',
    code: 'html htm css js json xml',
  };
  const kindOf = (name) => {
    const e = ext(name);
    for (const [k, list] of Object.entries(KINDS)) if (list.split(' ').includes(e)) return k;
    return 'file';
  };
  const THUMBABLE = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico']);
  const randomId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
  const joinPath = (...p) => p.filter(Boolean).join('/');
  const encodePath = (p) => p.split('/').filter(Boolean).map(encodeURIComponent).join('/');
  const decodePath = (p) => p.split('/').filter(Boolean).map(decodeURIComponent).join('/');

  async function copy(text, message) {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = el('textarea', { style: 'position:fixed;opacity:0' }, text);
      document.body.append(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast(message || t('link.copied'));
  }

  /* BBCode for posting on the forum. phpBB embeds these image types with [img]. */
  const BB_IMAGES = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);
  const bbcode = (e) => {
    if (e.type === 'file' && BB_IMAGES.has(ext(e.name))) return `[img]${e.url}[/img]`;
    // Brackets in the name would end the tag early.
    return `[url=${e.url}]${e.name.replace(/\[/g, '(').replace(/\]/g, ')')}[/url]`;
  };
  const copyBbcode = (entries) => {
    if (!entries.length) return;
    copy(entries.map(bbcode).join('\n'), t('bbcode.copied', { n: entries.length }));
  };

  /* ---------------------------------------------------------------- */
  /* State                                                            */
  /* ---------------------------------------------------------------- */

  const state = {
    me: null,
    view: 'files',
    path: '',
    listing: null,
    sort: store.get('pbput-sort', 'name:asc'),
    filter: '',
    selected: new Set(),
  };

  /* ---------------------------------------------------------------- */
  /* Top bar                                                          */
  /* ---------------------------------------------------------------- */

  function renderMe() {
    const me = state.me;
    $('.uname').textContent = me.user;
    $('.avatar').textContent = me.user.slice(0, 1).toUpperCase();
    const link = $('#public-link');
    link.href = me.publicUrl;
    link.textContent = me.publicUrl.replace(/^https?:\/\//, '');
    const list = $('#space-list');
    list.replaceChildren();
    if (me.dirs.length > 1) {
      list.append(el('hr'), el('div', { class: 'menu-head muted small' }, t('menu.folders')));
      for (const d of me.dirs) {
        list.append(el('button', {
          class: 'menu-item' + (d.current ? ' current' : ''),
          onclick: async () => {
            try {
              state.me = await api('space', { dir: d.id });
              state.listing = null;
              renderMe();
              navigate('', 'files');
              toast(t('menu.switched', { name: d.name }));
            } catch (e) { fail(e); }
          },
        }, d.name));
      }
    }
    renderUsage();
  }

  function renderUsage() {
    const { used, quota } = state.me;
    const u = $('#usage');
    u.classList.toggle('no-quota', !quota);
    u.classList.toggle('full', quota > 0 && used / quota > 0.9);
    $('i', u).style.width = quota ? Math.min(100, (used / quota) * 100) + '%' : '0';
    $('span', u).textContent = quota ? t('usage.of', { used: fmtBytes(used), quota: fmtBytes(quota) }) : t('usage.used', { used: fmtBytes(used) });
  }

  let usageTimer;
  function refreshUsage() {
    clearTimeout(usageTimer);
    usageTimer = setTimeout(async () => {
      try { Object.assign(state.me, await api('usage')); renderUsage(); } catch (e) { /* ignore */ }
    }, 800);
  }

  $('#user-btn').addEventListener('click', (e) => { e.stopPropagation(); $('#user-menu').hidden = !$('#user-menu').hidden; });
  document.addEventListener('click', (e) => { if (!e.target.closest('.menu-wrap')) $('#user-menu').hidden = true; });
  $('#logout-btn').addEventListener('click', async () => {
    if (uploads.active() && !confirmLeave()) return;
    try { await api('logout', {}); } finally { location.reload(); }
  });

  /* ---------------------------------------------------------------- */
  /* Routing                                                          */
  /* ---------------------------------------------------------------- */

  function parseHash() {
    const h = location.hash.replace(/^#/, '');
    const m = h.match(/^(files|design)(?:\/(.*))?$/);
    return m ? { view: m[1], path: decodePath(m[2] || '') } : { view: 'files', path: '' };
  }
  function navigate(path, view) {
    const v = view || state.view;
    const target = '#' + v + (path ? '/' + encodePath(path) : '');
    if (location.hash !== target) location.hash = target;
    else route();
  }
  async function route() {
    const { view, path } = parseHash();
    if (state.view === 'design' && view !== 'design' && design.dirty() && !confirm(t('confirm.discard_design'))) {
      history.replaceState(null, '', '#design' + (state.path ? '/' + encodePath(state.path) : ''));
      return;
    }
    const pathChanged = path !== state.path || !state.listing;
    state.view = view;
    state.path = path;
    for (const tab of $$('.tab')) {
      tab.classList.toggle('active', tab.dataset.view === view);
      tab.setAttribute('aria-selected', String(tab.dataset.view === view));
    }
    $('#view-files').hidden = view !== 'files';
    $('#view-design').hidden = view !== 'design';
    if (pathChanged) { state.selected.clear(); preview.reset(); await loadListing(); }
    if (view === 'design') design.load();
  }
  window.addEventListener('hashchange', route);
  for (const tab of $$('.tab')) tab.addEventListener('click', () => navigate(state.path, tab.dataset.view));

  /* ---------------------------------------------------------------- */
  /* File list                                                        */
  /* ---------------------------------------------------------------- */

  let listToken = 0;
  async function loadListing(quiet) {
    const token = ++listToken;
    if (!quiet) $('#loading').hidden = false;
    try {
      const data = await api('list', null, { path: state.path });
      if (token !== listToken) return;
      state.listing = data;
      // Keep selection only for entries that still exist.
      const ids = new Set(data.entries.map((e) => e.id));
      for (const id of state.selected) if (!ids.has(id)) state.selected.delete(id);
      renderListing();
    } catch (e) {
      if (token !== listToken) return;
      fail(e);
      if (e.status === 404 && state.path) navigate(state.path.split('/').slice(0, -1).join('/'));
    } finally {
      if (token === listToken) $('#loading').hidden = true;
    }
  }

  let refreshTimer;
  const refreshSoon = () => { clearTimeout(refreshTimer); refreshTimer = setTimeout(() => loadListing(true), 350); };

  function renderCrumbs() {
    const c = $('#crumbs');
    c.replaceChildren();
    const L = state.listing;
    const parts = [{ name: state.me.dir, path: '' }].concat(L.crumbs);
    parts.forEach((p, i) => {
      if (i) c.append(el('span', { class: 'sep' }, '/'));
      if (i === parts.length - 1) c.append(el('span', { class: 'here' }, p.name));
      else {
        const a = el('a', { href: '#' + state.view + (p.path ? '/' + encodePath(p.path) : '') }, p.name);
        makeDropTarget(a, p.path);
        c.append(a);
      }
    });
  }

  function sortedEntries() {
    const [key, dir] = state.sort.split(':');
    const f = state.filter.toLowerCase();
    const list = state.listing.entries.filter((e) => !f || e.name.toLowerCase().includes(f));
    const mul = dir === 'desc' ? -1 : 1;
    return list.sort((a, b) => {
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
      let r;
      if (key === 'size') r = (a.size || 0) - (b.size || 0);
      else if (key === 'mtime') r = a.mtime - b.mtime;
      else r = a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' });
      return r * mul;
    });
  }

  function renderListing() {
    const L = state.listing;
    renderCrumbs();
    $('#readonly-notice').hidden = L.writable;
    renderPageBar(L);
    preview.onListing();
    $('#drop-target').textContent = state.path ? state.path.split('/').pop() : state.me.dir;
    const [key, dir] = state.sort.split(':');
    for (const th of $$('th[data-sort]')) {
      th.classList.toggle('sorted', th.dataset.sort === key);
      th.classList.toggle('asc', th.dataset.sort === key && dir === 'asc');
    }

    const rows = $('#file-rows');
    rows.replaceChildren();
    const entries = sortedEntries();
    for (const e of entries) rows.append(renderRow(e));
    $('#empty').hidden = L.entries.length > 0;
    $('#files').hidden = L.entries.length === 0;
    renderSelection();
  }

  /** Public address of this folder and what visitors get there. */
  function renderPageBar(L) {
    const link = $('#page-url');
    link.href = L.url;
    link.textContent = L.url.replace(/^https?:\/\//, '');
    const st = L.entries.some((e) => e.special) ? 'design' : 'list';
    const chip = $('#page-state');
    chip.className = 'chip chip-' + st;
    chip.textContent = t('page.state_' + st);
    chip.title = t('page.state_' + st + '_title');
  }
  $('#page-copy').addEventListener('click', () => copy($('#page-url').href));

  function renderRow(e) {
    const isDir = e.type === 'dir';
    const kind = isDir ? 'dir' : kindOf(e.name);
    const thumb = el('div', { class: 'thumb ' + kind });
    if (!isDir && THUMBABLE.has(ext(e.name)) && e.size < 15e6) {
      const img = el('img', { src: e.url, loading: 'lazy', alt: '' });
      img.addEventListener('error', () => img.replaceWith(icon('image')));
      thumb.append(img);
    } else {
      thumb.append(icon(isDir ? 'folder' : kind === 'file' ? 'file' : kind));
    }

    const nameLink = isDir
      ? el('a', { href: '#' + state.view + '/' + encodePath(joinPath(state.path, e.id)) }, e.name)
      : el('a', { href: e.url, target: '_blank', rel: 'noopener' }, e.name);
    if (!isDir) {
      // With the preview open a plain click previews; ctrl/middle-click still opens a tab.
      nameLink.addEventListener('click', (ev) => {
        if (preview.isOpen() && ev.button === 0 && !ev.ctrlKey && !ev.metaKey && !ev.shiftKey) {
          ev.preventDefault();
          preview.focus(e);
        }
      });
    }
    const cell = el('div', { class: 'name-cell' }, thumb, nameLink);
    if (e.special) cell.append(el('span', { class: 'tag', title: t('tag.special_title') }, t('tag.' + e.special)));
    if (e.badName) cell.append(el('span', { class: 'tag warn', title: t('tag.old_encoding_title') }, t('tag.old_encoding')));

    const check = el('input', { type: 'checkbox', 'aria-label': t('files.select', { name: e.name }) });
    check.checked = state.selected.has(e.id);
    check.addEventListener('change', () => {
      if (check.checked) state.selected.add(e.id); else state.selected.delete(e.id);
      tr.classList.toggle('selected', check.checked);
      renderSelection();
    });

    const actions = el('div', { class: 'row-actions' },
      el('button', { class: 'icon-btn', title: t('row.copy_link'), onclick: () => copy(e.url) }, icon('link')),
      el('button', { class: 'icon-btn', title: t('row.copy_bbcode'), onclick: () => copyBbcode([e]) }, icon('bbcode')),
      el('button', { class: 'icon-btn', title: t('row.more'), onclick: (ev) => rowMenu(ev, e) }, icon('dots')));

    const tr = el('tr', { class: state.selected.has(e.id) ? 'selected' : '', draggable: 'true' },
      el('td', { class: 'col-check' }, check),
      el('td', { class: 'col-name' }, cell),
      el('td', { class: 'col-size' }, isDir ? '—' : fmtBytes(e.size)),
      el('td', { class: 'col-date' }, fmtDate(e.mtime)),
      el('td', { class: 'col-actions' }, actions));
    tr.dataset.id = e.id;
    tr.addEventListener('contextmenu', (ev) => { ev.preventDefault(); rowMenu(ev, e); });
    if (!isDir) {
      tr.addEventListener('click', (ev) => {
        if (!ev.target.closest('a, button, input') && preview.isOpen()) preview.focus(e);
      });
    }
    if (preview.focused() === e.id) tr.classList.add('focused');
    tr.addEventListener('dragstart', (ev) => {
      const ids = state.selected.has(e.id) ? [...state.selected] : [e.id];
      ev.dataTransfer.setData('application/x-pbput', JSON.stringify({ from: state.path, ids }));
      ev.dataTransfer.setData('text/uri-list', e.url);
      ev.dataTransfer.effectAllowed = 'move';
    });
    if (isDir) makeDropTarget(tr, joinPath(state.path, e.id));
    return tr;
  }

  function renderSelection() {
    const n = state.selected.size;
    $('#selection-bar').hidden = n === 0;
    $('#selection-count').textContent = t('sel.count', { n });
    const all = $('#check-all');
    const visible = $$('#file-rows tr').length;
    all.checked = n > 0 && n >= visible;
    all.indeterminate = n > 0 && n < visible;
  }

  $('#check-all').addEventListener('change', (e) => {
    state.selected.clear();
    if (e.target.checked) for (const tr of $$('#file-rows tr')) state.selected.add(tr.dataset.id);
    renderListing();
  });
  $('#sel-clear').addEventListener('click', () => { state.selected.clear(); renderListing(); });
  $('#sel-delete').addEventListener('click', () => confirmDelete([...state.selected]));
  $('#sel-move').addEventListener('click', () => moveDialog([...state.selected]));
  // In the order shown, so a photo series is posted in sequence.
  $('#sel-bbcode').addEventListener('click', () => copyBbcode(sortedEntries().filter((e) => state.selected.has(e.id))));
  $('#filter').addEventListener('input', (e) => { state.filter = e.target.value; renderListing(); });
  for (const th of $$('th[data-sort]')) {
    th.addEventListener('click', () => {
      const [key, dir] = state.sort.split(':');
      const k = th.dataset.sort;
      state.sort = k + ':' + (k === key && dir === 'asc' ? 'desc' : 'asc');
      store.set('pbput-sort', state.sort);
      renderListing();
    });
  }

  /* ---------------------------------------------------------------- */
  /* Preview panel: the folder's public page, or a clicked file        */
  /* ---------------------------------------------------------------- */

  const preview = (() => {
    const PAGE_W = 760; // the public page is rendered at this width and scaled down
    const panel = $('#pv');
    const page = $('#pv-page');
    const frame = $('#pv-frame');
    const fileBox = $('#pv-file');
    const toggle = $('#preview-toggle');
    const pref = store.get('pbput-preview', '');
    let open = pref ? pref === '1' : window.matchMedia('(min-width: 1100px)').matches;
    let focusedEntry = null;
    let token = 0;
    let timer;
    let shown = null; // { html, title } of rendered HTML currently in the panel, for pop-out
    const popBtn = $('#pv-pop');
    const setShown = (html, title) => { shown = html ? { html, title } : null; popBtn.hidden = !shown; };
    popBtn.addEventListener('click', () => shown && popOut(shown.html, shown.title));

    // Scale the 1000px-wide page down to the panel, like a thumbnail of the site.
    new ResizeObserver(() => {
      const s = page.clientWidth / PAGE_W;
      frame.style.transform = `scale(${s})`;
      frame.style.height = page.clientHeight / s + 'px';
    }).observe(page);

    function setOpen(v) {
      open = v;
      store.set('pbput-preview', v ? '1' : '0');
      panel.hidden = !v;
      $('#browse').classList.toggle('with-pv', v);
      document.body.classList.toggle('wide', v);
      toggle.setAttribute('aria-pressed', String(v));
      if (!v) clear(); else refresh();
    }
    toggle.addEventListener('click', () => setOpen(!open));

    function header(title, sub, href, isFile) {
      $('#pv-title').textContent = title;
      $('#pv-sub').textContent = sub;
      $('#pv-open').href = href;
      $('#pv-back').hidden = !isFile;
      page.hidden = isFile;
      fileBox.hidden = !isFile;
      setShown(null);
    }

    async function showFolder() {
      const L = state.listing;
      header(t('pv.title'), t('pv.folder_hint'), L.url, false);
      const mine = ++token;
      let r;
      try {
        r = await api('folder-preview', null, { path: state.path });
      } catch (e) {
        return;
      }
      if (mine !== token || focusedEntry) return;
      frame.srcdoc = folderPageHtml(L, r.header, r.footer);
      setShown(frame.srcdoc, L.url.replace(/^https?:\/\//, ''));
    }

    /** HTML files are shown rendered, as cleaned by the server, in a sandboxed frame. */
    async function loadHtml(e, box, meta) {
      const mine = ++token;
      let r;
      try {
        r = await api('file-html', null, { path: state.path, id: e.id });
      } catch (err) {
        box.replaceWith(el('div', { class: 'pv-icon' }, icon('code'), el('span', { class: 'muted small' }, err.message)));
        return;
      }
      if (mine !== token || !focusedEntry || focusedEntry.id !== e.id) return;
      const html = pageDoc(r.html, state.listing.url, e.name);
      box.srcdoc = html;
      setShown(html, e.name);
      if (r.removed.length) meta.textContent = t('pv.removed', { items: r.removed.join(', ') });
    }

    function showFile(e) {
      header(e.name, [fmtBytes(e.size), fmtDate(e.mtime)].join(' · '), e.url, true);
      const kind = kindOf(e.name);
      const meta = el('div', { class: 'muted small pv-meta' });
      let media;
      if (THUMBABLE.has(ext(e.name))) {
        media = el('img', { src: e.url, alt: e.name });
        media.addEventListener('load', () => { meta.textContent = t('pv.dims', { w: media.naturalWidth, h: media.naturalHeight }); });
      } else if (kind === 'video') {
        media = el('video', { src: e.url, controls: true, preload: 'metadata' });
      } else if (kind === 'audio') {
        media = el('audio', { src: e.url, controls: true, preload: 'metadata' });
      } else if (HTML_EXT.has(ext(e.name))) {
        media = el('iframe', { class: 'pv-html', sandbox: '', title: e.name });
        loadHtml(e, media, meta);
      } else {
        media = el('div', { class: 'pv-icon thumb ' + kind }, icon(kind === 'file' ? 'file' : kind), el('span', { class: 'muted small' }, t('pv.no_preview')));
      }
      fileBox.replaceChildren(
        el('div', { class: 'pv-media' }, media), meta,
        el('div', { class: 'pv-actions' },
          el('a', { class: 'btn btn-sm', href: e.url, target: '_blank', rel: 'noopener' }, icon('external'), t('pv.open')),
          el('button', { class: 'btn btn-sm', onclick: () => copy(e.url) }, icon('link'), t('row.copy_link')),
          el('button', { class: 'btn btn-sm', onclick: () => copyBbcode([e]) }, icon('bbcode'), 'BBCode')));
    }

    function markRows() {
      for (const tr of $$('#file-rows tr')) tr.classList.toggle('focused', !!focusedEntry && tr.dataset.id === focusedEntry.id);
    }

    function focus(e) {
      focusedEntry = e;
      markRows();
      showFile(e);
    }

    function clear() {
      if (!focusedEntry) return;
      focusedEntry = null;
      markRows();
      if (open && state.listing) showFolder();
    }
    $('#pv-back').addEventListener('click', clear);
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && focusedEntry && !$('#modal').open) clear();
    });

    function refresh() {
      if (!open || !state.listing) return;
      if (focusedEntry) showFile(focusedEntry);
      else showFolder();
    }

    /** New or refreshed listing: drop a focus that no longer exists, re-render (debounced). */
    function onListing() {
      if (focusedEntry) {
        const still = state.listing.entries.find((x) => x.id === focusedEntry.id);
        focusedEntry = still || null;
      }
      clearTimeout(timer);
      timer = setTimeout(refresh, 250);
    }

    setOpen(open);
    return { focus, onListing, reset: () => { focusedEntry = null; }, isOpen: () => open, focused: () => focusedEntry && focusedEntry.id };
  })();

  /* Row context menu */
  let openMenu = null;
  function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  document.addEventListener('click', closeMenu);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('scroll', closeMenu, true);

  function rowMenu(ev, e) {
    ev.stopPropagation();
    closeMenu();
    const item = (label, fn, cls) => el('button', { class: 'menu-item ' + (cls || ''), onclick: () => { closeMenu(); fn(); } }, label);
    const m = el('div', { class: 'menu ctx' },
      e.type === 'dir'
        ? item(t('row.open'), () => navigate(joinPath(state.path, e.id)))
        : item(t('row.open_tab'), () => window.open(e.url, '_blank', 'noopener')),
      item(t('row.copy_link'), () => copy(e.url)),
      item(t('row.copy_bbcode'), () => copyBbcode([e])),
      e.type === 'file' ? item(t('row.download'), () => { const a = el('a', { href: e.url, download: e.name }); document.body.append(a); a.click(); a.remove(); }) : null,
      el('hr'),
      item(t('row.rename'), () => renameDialog(e)),
      item(t('row.move'), () => moveDialog([e.id])),
      el('hr'),
      item(t('row.delete'), () => confirmDelete([e.id]), 'btn-danger'));
    document.body.append(m);
    const r = m.getBoundingClientRect();
    m.style.left = Math.min(ev.clientX, innerWidth - r.width - 8) + 'px';
    m.style.top = Math.min(ev.clientY, innerHeight - r.height - 8) + 'px';
    openMenu = m;
  }

  /* ---------------------------------------------------------------- */
  /* Operations                                                       */
  /* ---------------------------------------------------------------- */

  $('#new-folder-btn').addEventListener('click', () =>
    promptName(t('mkdir.title'), '', t('mkdir.ok'), async (name) => {
      const r = await api('mkdir', { path: state.path, name });
      await loadListing(true);
      flash(r.name);
      return true;
    }));

  function renameDialog(e) {
    return promptName(t('rename.title'), e.name, t('rename.ok'), async (name) => {
      const r = await api('rename', { path: state.path, id: e.id, name });
      state.selected.delete(e.id);
      await loadListing(true);
      flash(r.name);
      return true;
    });
  }

  function confirmDelete(ids) {
    const names = ids.map((id) => (state.listing.entries.find((x) => x.id === id) || { name: id }).name);
    const dirs = ids.filter((id) => (state.listing.entries.find((x) => x.id === id) || {}).type === 'dir').length;
    return modal({
      title: ids.length === 1 ? t('delete.title_one', { name: names[0] }) : t('delete.title_many', { n: ids.length }),
      text: (dirs ? t('delete.folders') + ' ' : '') + t('delete.warning'),
      ok: t('delete.ok'),
      danger: true,
      onOk: async () => {
        const r = await api('delete', { path: state.path, ids });
        for (const id of ids) state.selected.delete(id);
        await loadListing(true);
        refreshUsage();
        toast(t('delete.done', { n: r.count }));
        return true;
      },
    });
  }

  async function doMove(from, ids, dest) {
    try {
      const r = await api('move', { path: from, ids, dest });
      for (const id of ids) state.selected.delete(id);
      await loadListing(true);
      toast(t('move.done', { n: r.count, dest: dest ? dest.split('/').pop() : state.me.dir }));
    } catch (e) { fail(e); }
  }

  /** Folder picker that lazily loads sub folders. */
  function moveDialog(ids) {
    let chosen = null;
    const tree = el('div', { class: 'tree' });
    const moving = new Set(ids.map((id) => joinPath(state.path, id)));
    const addLevel = async (container, path, depth) => {
      try {
        const data = await api('list', null, { path });
        for (const e of data.entries.filter((x) => x.type === 'dir')) {
          const p = joinPath(path, e.id);
          if (moving.has(p)) continue;
          const kids = el('div');
          let loaded = false;
          const row = el('div', { class: 'tree-item', style: `padding-left:${8 + depth * 18}px` }, icon('folder'), e.name);
          row.addEventListener('click', () => {
            $$('.tree-item', tree).forEach((x) => x.classList.remove('sel'));
            row.classList.add('sel');
            chosen = p;
            if (!loaded) { loaded = true; addLevel(kids, p, depth + 1); }
          });
          container.append(row, kids);
        }
      } catch (e) { fail(e); }
    };
    const rootRow = el('div', { class: 'tree-item' }, icon('folder'), el('b', {}, state.me.dir));
    rootRow.addEventListener('click', () => {
      $$('.tree-item', tree).forEach((x) => x.classList.remove('sel'));
      rootRow.classList.add('sel');
      chosen = '';
    });
    tree.append(rootRow);
    addLevel(tree, '', 1);
    return modal({
      title: t('move.title', { n: ids.length }),
      text: t('move.text'),
      body: tree,
      ok: t('move.ok'),
      onOk: async () => {
        if (chosen === null) throw new Error(t('move.pick'));
        if (chosen === state.path) return true;
        await doMove(state.path, ids, chosen);
        return true;
      },
    });
  }

  function flash(name) {
    requestAnimationFrame(() => {
      const tr = $$('#file-rows tr').find((r) => r.dataset.id === name);
      if (tr) { tr.classList.add('fresh'); tr.scrollIntoView({ block: 'nearest' }); }
    });
  }

  /* ---------------------------------------------------------------- */
  /* Drag & drop                                                      */
  /* ---------------------------------------------------------------- */

  const isInternal = (dt) => dt && Array.from(dt.types).includes('application/x-pbput');
  const hasFiles = (dt) => dt && Array.from(dt.types).includes('Files');

  function makeDropTarget(node, destPath) {
    node.addEventListener('dragover', (ev) => {
      if (!isInternal(ev.dataTransfer) && !hasFiles(ev.dataTransfer)) return;
      ev.preventDefault();
      ev.stopPropagation();
      ev.dataTransfer.dropEffect = isInternal(ev.dataTransfer) ? 'move' : 'copy';
      node.classList.add('drop-over');
      $('#dropzone').hidden = true;
    });
    node.addEventListener('dragleave', () => node.classList.remove('drop-over'));
    node.addEventListener('drop', (ev) => {
      ev.preventDefault();
      ev.stopPropagation();
      node.classList.remove('drop-over');
      hideDrop();
      if (isInternal(ev.dataTransfer)) {
        const { from, ids } = JSON.parse(ev.dataTransfer.getData('application/x-pbput'));
        if (from === destPath) return;
        doMove(from, ids.filter((id) => joinPath(from, id) !== destPath), destPath);
      } else {
        uploads.fromDataTransfer(ev.dataTransfer, destPath);
      }
    });
  }

  let dragDepth = 0;
  const hideDrop = () => { dragDepth = 0; $('#dropzone').hidden = true; };
  window.addEventListener('dragenter', (ev) => {
    if (!hasFiles(ev.dataTransfer) || state.view !== 'files') return;
    dragDepth++;
    $('#dropzone').hidden = false;
  });
  window.addEventListener('dragleave', () => { if (--dragDepth <= 0) hideDrop(); });
  window.addEventListener('dragover', (ev) => {
    if (hasFiles(ev.dataTransfer)) { ev.preventDefault(); ev.dataTransfer.dropEffect = state.view === 'files' ? 'copy' : 'none'; }
  });
  window.addEventListener('drop', (ev) => {
    if (!hasFiles(ev.dataTransfer)) return;
    ev.preventDefault();
    hideDrop();
    if (state.view === 'files') uploads.fromDataTransfer(ev.dataTransfer, state.path);
  });

  $('#file-input').addEventListener('change', (e) => {
    uploads.add(Array.from(e.target.files).map((f) => ({ file: f, rel: f.name })), state.path);
    e.target.value = '';
  });

  /* ---------------------------------------------------------------- */
  /* Uploads                                                          */
  /* ---------------------------------------------------------------- */

  const uploads = (() => {
    const CONCURRENCY = 3;
    const queue = [];
    let running = 0;

    const panel = $('#uploads');
    const list = $('#uploads-list');
    $('#uploads-min').addEventListener('click', () => panel.classList.toggle('min'));
    $('#uploads-close').addEventListener('click', () => {
      for (const u of queue.filter((x) => x.status === 'done' || x.status === 'error' || x.status === 'cancelled')) {
        u.li.remove();
        queue.splice(queue.indexOf(u), 1);
      }
      if (!queue.length) panel.hidden = true;
      updateTitle();
    });

    function updateTitle() {
      const active = queue.filter((u) => u.status === 'queued' || u.status === 'uploading');
      const done = queue.filter((u) => u.status === 'done').length;
      const failed = queue.filter((u) => u.status === 'error').length;
      $('#uploads-title').textContent = active.length
        ? t('up.active', { n: active.length })
        : t('up.summary', { n: done }) + (failed ? ', ' + t('up.failed', { n: failed }) : '');
    }

    function add(items, destPath) {
      const blocked = new Set(state.me.blocked);
      for (const { file, rel } of items) {
        const u = {
          file, rel, dest: destPath, id: randomId(), sent: 0, status: 'queued', xhr: null, t0: 0,
        };
        u.bar = el('i');
        u.meta = el('div', { class: 'up-meta' }, t('up.waiting'));
        u.btn = el('button', { class: 'icon-btn', title: t('up.cancel'), onclick: () => cancel(u) }, icon('x'));
        u.li = el('li', { class: 'up' }, el('div', { class: 'up-name', title: rel }, rel), u.btn, u.meta, el('div', { class: 'up-bar' }, u.bar));
        list.prepend(u.li);
        queue.push(u);
        const parts = file.name.toLowerCase().split('.').slice(1);
        if (file.name.startsWith('.')) setError(u, t('up.hidden'));
        else if (parts.some((p) => blocked.has(p))) setError(u, t('up.blocked'));
        else if (file.size > state.me.maxFile) setError(u, t('up.too_large', { limit: fmtBytes(state.me.maxFile) }));
      }
      panel.hidden = false;
      panel.classList.remove('min');
      updateTitle();
      pump();
    }

    function setError(u, msg) {
      u.status = 'error';
      u.li.className = 'up error';
      u.meta.textContent = msg;
      u.btn.replaceChildren(icon('alert'));
      u.btn.title = msg;
      u.btn.onclick = null;
    }

    function cancel(u) {
      if (u.status === 'uploading' && u.xhr) u.xhr.abort();
      if (u.status === 'uploading' || u.status === 'queued') {
        const wasRunning = u.status === 'uploading';
        u.status = 'cancelled';
        u.li.className = 'up error';
        u.meta.textContent = t('up.cancelled');
        u.btn.replaceChildren(icon('x'));
        u.btn.onclick = () => { u.li.remove(); queue.splice(queue.indexOf(u), 1); updateTitle(); };
        if (wasRunning) api('abort', { id: u.id }).catch(() => {});
        updateTitle();
      }
    }

    function pump() {
      while (running < CONCURRENCY) {
        const u = queue.find((x) => x.status === 'queued');
        if (!u) break;
        running++;
        run(u).finally(() => { running--; updateTitle(); pump(); });
      }
    }

    function sendChunk(u, offset, blob) {
      return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('path', u.dest);
        fd.append('name', u.rel);
        fd.append('id', u.id);
        fd.append('size', String(u.file.size));
        fd.append('offset', String(offset));
        fd.append('chunk', blob, 'chunk');
        const xhr = new XMLHttpRequest();
        u.xhr = xhr;
        xhr.open('POST', 'api.php?a=chunk');
        xhr.setRequestHeader('X-CSRF-Token', boot.csrf);
        xhr.responseType = 'json';
        xhr.upload.onprogress = (e) => progress(u, offset + e.loaded);
        xhr.onload = () => {
          const data = xhr.response || {};
          if (xhr.status === 200) resolve(data);
          else reject(new ApiError(data.error || t('up.failed_status', { status: xhr.status }), xhr.status, data));
        };
        xhr.onerror = () => reject(new ApiError(t('err.network'), 0, {}));
        xhr.onabort = () => reject(new ApiError(t('up.cancelled'), -1, {}));
        xhr.send(fd);
      });
    }

    function progress(u, bytes) {
      const pct = u.file.size ? Math.min(100, (bytes / u.file.size) * 100) : 100;
      u.bar.style.width = pct + '%';
      const secs = (performance.now() - u.t0) / 1000;
      const rate = secs > 0.5 ? (bytes - u.startBytes) / secs : 0;
      const left = rate > 0 ? (u.file.size - bytes) / rate : 0;
      const parts = [t('up.progress', { done: fmtBytes(bytes), total: fmtBytes(u.file.size) })];
      if (rate) parts.push(t('up.rate', { rate: fmtBytes(rate) }));
      if (rate && left > 2) parts.push(left < 90 ? t('up.left_s', { n: Math.round(left) }) : t('up.left_min', { n: Math.round(left / 60) }));
      u.meta.textContent = parts.join(' · ');
    }

    async function run(u) {
      u.status = 'uploading';
      u.li.className = 'up';
      u.t0 = performance.now();
      u.startBytes = 0;
      const chunk = state.me.chunk;
      let offset = 0;
      let retries = 0;
      try {
        for (;;) {
          const blob = u.file.slice(offset, Math.min(offset + chunk, u.file.size));
          let r;
          try {
            r = await sendChunk(u, offset, blob);
          } catch (e) {
            if (u.status === 'cancelled') return;
            if (e.status === 409 && typeof e.data.offset === 'number') { offset = e.data.offset; continue; }
            // Retry transient failures (network, gateway timeouts) with backoff.
            if ((e.status === 0 || e.status >= 502) && retries < 5) {
              retries++;
              u.meta.textContent = t('up.retrying', { n: retries });
              await new Promise((res) => setTimeout(res, 1000 * 2 ** retries));
              continue;
            }
            throw e;
          }
          retries = 0;
          if (r.done) {
            u.status = 'done';
            u.li.className = 'up done';
            u.bar.style.width = '100%';
            u.meta.textContent = fmtBytes(u.file.size) + (r.name !== u.rel.split('/').pop() ? ' · ' + t('up.saved_as', { name: r.name }) : '');
            u.btn.replaceChildren(icon('check'));
            u.btn.title = t('row.copy_link');
            u.btn.onclick = () => copy(r.url);
            if (u.dest === state.path || u.rel.includes('/')) refreshSoon();
            refreshUsage();
            return;
          }
          offset = r.offset;
        }
      } catch (e) {
        if (u.status !== 'cancelled') setError(u, e.message);
      }
    }

    /** Expand a drop into files, walking dropped folders. */
    async function fromDataTransfer(dt, destPath) {
      const entries = Array.from(dt.items || [])
        .filter((i) => i.kind === 'file')
        .map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null));
      if (!entries.length || entries.some((e) => !e)) {
        add(Array.from(dt.files).map((f) => ({ file: f, rel: f.name })), destPath);
        return;
      }
      const out = [];
      const walk = async (entry, prefix) => {
        if (entry.isFile) {
          const file = await new Promise((res, rej) => entry.file(res, rej));
          out.push({ file, rel: prefix + file.name });
        } else if (entry.isDirectory) {
          if (entry.name.startsWith('.')) return;
          const reader = entry.createReader();
          for (;;) {
            const batch = await new Promise((res, rej) => reader.readEntries(res, rej));
            if (!batch.length) break;
            for (const child of batch) await walk(child, prefix + entry.name + '/');
          }
        }
      };
      try {
        for (const e of entries) await walk(e, '');
      } catch (e) {
        fail(new Error(t('up.read_folder')));
      }
      // Skip OS junk files silently.
      add(out.filter((x) => !/(^|\/)(\.DS_Store|Thumbs\.db|desktop\.ini)$/i.test(x.rel)), destPath);
    }

    return {
      add,
      fromDataTransfer,
      active: () => queue.some((u) => u.status === 'queued' || u.status === 'uploading'),
    };
  })();

  const confirmLeave = () => confirm(t('confirm.leave_uploads'));
  window.addEventListener('beforeunload', (e) => {
    if (uploads.active() || (state.view === 'design' && design.dirty())) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ---------------------------------------------------------------- */
  /* Page design (HEADER.html / README.html)                          */
  /* ---------------------------------------------------------------- */

  /* ---------------------------------------------------------------- */
  /* Visual editor for header/footer                                  */
  /* ---------------------------------------------------------------- */

  /*
   * Page background colour, kept as one managed <style> block at the very top
   * of HEADER.html (Apache inserts the header inside <body>). Dark colours also
   * get light text and link colours so the file listing stays readable.
   */
  const BG_DEFAULT = '#1e1e1e';
  const BG_RE = /^<style id="pbput-bg">body\{background-color:(#[0-9a-fA-F]{6})[^<]*<\/style>\n?/;
  const splitBg = (html) => { const m = html.match(BG_RE); return m ? [m[0], html.slice(m[0].length)] : ['', html]; };
  const bgOf = (html) => { const m = html.match(BG_RE); return m ? m[1].toLowerCase() : ''; };
  const isDark = (hex) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b < 0.25;
  };
  const bgCss = (hex) => `body{background-color:${hex}` +
    (isDark(hex) ? ';color:#e6e6e6}a{color:#8ab4f8}a:visited{color:#c9a8f5}' : '}');
  const bgBlock = (hex) => (hex ? `<style id="pbput-bg">${bgCss(hex)}</style>\n` : '');

  /** Allow http(s), mailto and relative links; refuse javascript:, data: and friends. */
  const safeUrl = (v, images) => {
    const s = (v || '').trim();
    if (!s) return false;
    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) return images ? /^https?:/i.test(s) : /^(https?|mailto):/i.test(s);
    return !s.startsWith('//');
  };

  /**
   * WYSIWYG editing of a textarea's HTML inside a sandboxed iframe without
   * allow-scripts, so the user's own <style>/<script> can neither run nor
   * restyle this page. The textarea stays the source of truth and is only
   * rewritten when the user actually edits here; switching modes alone never
   * touches their code.
   */
  function visualEditor(card, { onSave, baseUrl, split = (html) => ['', html], pageStyle = () => '' }) {
    const ta = $('textarea', card);
    const wrap = $('.rte', card);
    const frame = $('.rte-frame', card);
    const bar = $('.rte-toolbar', card);
    const note = $('.rte-note', card);
    let doc = null;
    let range = null;
    let pending = false;
    const stateful = [];

    const CSS = ':root{color-scheme:light}html{cursor:text;height:100%}body{margin:8px;min-height:calc(100% - 16px);overflow-wrap:break-word}' +
      'img{max-width:100%}body.empty::before{content:attr(data-ph);color:#9aa0aa;position:absolute;pointer-events:none}';
    frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>${CSS}</style></head><body></body></html>`;
    frame.addEventListener('load', () => {
      doc = frame.contentDocument;
      const body = doc.body;
      doc.documentElement.lang = boot.lang || ''; // spell-check in the user's language
      body.contentEditable = 'true';
      body.dataset.ph = t('rte.placeholder');
      try {
        doc.execCommand('defaultParagraphSeparator', false, 'p');
        doc.execCommand('styleWithCSS', false, false);
      } catch (e) { /* older engines */ }
      body.addEventListener('input', sync);
      doc.addEventListener('selectionchange', () => { saveRange(); updateState(); });
      doc.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); onSave(); }
      });
      doc.addEventListener('paste', onPaste);
      // Dropped image files would otherwise be inlined as huge data: URLs.
      for (const ev of ['dragover', 'drop']) {
        doc.addEventListener(ev, (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) e.preventDefault(); });
      }
      if (pending) refresh();
    });

    /* Content <-> textarea */

    function refresh() {
      if (!doc) { pending = true; return; }
      pending = false;
      const base = baseUrl();
      if (base) $('base', doc.head).href = base; // relative image/link paths resolve like on the real page
      // A managed prefix (the background block) is kept out of the editable
      // content so it can't be deleted by accident; it is shown via <head>.
      const [prefix, rest] = split(ta.value);
      doc.body.innerHTML = rest;
      decorate(prefix);
      note.hidden = !/<(style|script)\b/i.test(rest);
      range = null;
      markEmpty();
      updateState();
    }

    const isEmpty = () => !doc.body.textContent.trim() && !doc.body.querySelector('img,hr,table,iframe,video,audio,object,embed,style,script');
    const markEmpty = () => doc.body.classList.toggle('empty', isEmpty());

    function decorate(prefix) {
      let style = doc.getElementById('pbput-managed');
      if (!style) {
        style = doc.createElement('style');
        style.id = 'pbput-managed';
        doc.head.append(style);
      }
      // Own managed prefix, or the page-wide one (the footer shows the header's background).
      style.textContent = (prefix || pageStyle()).replace(/<\/?style[^>]*>/g, '');
    }

    function sync() {
      ta.value = split(ta.value)[0] + (isEmpty() ? '' : doc.body.innerHTML);
      markEmpty();
      ta.dispatchEvent(new Event('input'));
    }

    /* Selection & commands */

    function saveRange() {
      const sel = doc.getSelection();
      if (sel.rangeCount && doc.body.contains(sel.anchorNode)) range = sel.getRangeAt(0).cloneRange();
    }

    function focusEditor() {
      frame.contentWindow.focus();
      if (range) {
        const sel = doc.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
    }

    function exec(cmd, value) {
      focusEditor();
      doc.execCommand(cmd, false, value);
      saveRange();
      sync();
      updateState();
    }

    function updateState() {
      if (!doc) return;
      for (const s of stateful) s();
    }

    /* Paste: keep basic structure, drop styles, classes, scripts and junk. */

    const KEEP = {
      P: [], BR: [], B: [], STRONG: [], I: [], EM: [], U: [], A: ['href'], UL: [], OL: [], LI: [],
      H1: [], H2: [], H3: [], H4: [], IMG: ['src', 'alt'], HR: [], BLOCKQUOTE: [],
    };
    function cleanHtml(html) {
      const src = new DOMParser().parseFromString(html, 'text/html').body;
      const out = doc.createElement('div');
      const walk = (from, to) => {
        for (const n of Array.from(from.childNodes)) {
          if (n.nodeType === 3) { to.append(n.textContent); continue; }
          if (n.nodeType !== 1 || /^(SCRIPT|STYLE|META|LINK|TITLE|TEMPLATE|IFRAME|OBJECT|EMBED|SVG|NOSCRIPT)$/.test(n.tagName)) continue;
          const attrs = KEEP[n.tagName];
          if (!attrs) {
            walk(n, to);
            if (/^(DIV|TR|SECTION|ARTICLE|HEADER|FOOTER)$/.test(n.tagName)) to.append(doc.createElement('br'));
            continue;
          }
          // Unsafe link: keep its text. Unsafe image: drop it.
          if (n.tagName === 'A' && !safeUrl(n.getAttribute('href'))) { walk(n, to); continue; }
          if (n.tagName === 'IMG' && !safeUrl(n.getAttribute('src'), true)) continue;
          const c = doc.createElement(n.tagName);
          for (const a of attrs) {
            const v = n.getAttribute(a);
            if (v) c.setAttribute(a, v);
          }
          walk(n, c);
          to.append(c);
        }
      };
      walk(src, out);
      return out.innerHTML;
    }

    function onPaste(e) {
      const html = e.clipboardData.getData('text/html');
      const text = e.clipboardData.getData('text/plain');
      e.preventDefault(); // also refuses pasted image files (data: URLs)
      if (html) doc.execCommand('insertHTML', false, cleanHtml(html));
      else if (text) doc.execCommand('insertText', false, text);
      saveRange();
      sync();
    }

    /* Dialogs */

    const field = (label, input) => el('label', { class: 'field' }, el('span', {}, label), input);

    function linkDialog() {
      focusEditor();
      const sel = doc.getSelection();
      const node = sel.anchorNode && (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement);
      const existing = node && node.closest('a');
      const selected = existing ? existing.textContent : sel.toString();
      const url = el('input', { value: existing ? existing.getAttribute('href') || '' : '', placeholder: 'https://', autocomplete: 'off' });
      const text = el('input', { value: selected, autocomplete: 'off' });
      const newTab = el('input', { type: 'checkbox' });
      newTab.checked = existing ? existing.target === '_blank' : true;
      const body = el('div', {}, field(t('link.url'), url), field(t('link.text'), text),
        el('label', { class: 'check-row' }, newTab, t('link.new_tab')));
      const saved = range;
      return modal({
        title: t('link.title'), body, ok: t('link.ok'), focus: url,
        onOk: () => {
          const href = url.value.trim();
          if (!safeUrl(href)) throw new Error(t('link.invalid'));
          range = saved;
          focusEditor();
          if (existing) {
            existing.setAttribute('href', href);
            if (text.value && text.value !== existing.textContent) existing.textContent = text.value;
            if (newTab.checked) { existing.target = '_blank'; existing.rel = 'noopener'; } else { existing.removeAttribute('target'); existing.removeAttribute('rel'); }
            sync();
          } else if (selected && text.value === selected) {
            // Keeps any formatting inside the selection.
            exec('createLink', href);
            const a = doc.getSelection().anchorNode;
            const link = a && (a.nodeType === 1 ? a : a.parentElement).closest('a');
            if (link && newTab.checked) { link.target = '_blank'; link.rel = 'noopener'; sync(); }
          } else {
            const a = doc.createElement('a');
            a.href = href;
            a.textContent = text.value || href;
            if (newTab.checked) { a.target = '_blank'; a.rel = 'noopener'; }
            exec('insertHTML', a.outerHTML);
          }
          return true;
        },
      });
    }

    function imageDialog() {
      const saved = range;
      const url = el('input', { placeholder: 'https://', autocomplete: 'off' });
      const alt = el('input', { autocomplete: 'off' });
      const images = (state.listing ? state.listing.entries : []).filter((e) => e.type === 'file' && THUMBABLE.has(ext(e.name)));
      const grid = el('div', { class: 'img-grid' });
      for (const e of images) {
        const b = el('button', { type: 'button', title: e.name }, el('img', { src: e.url, alt: '', loading: 'lazy' }));
        b.addEventListener('click', () => {
          $$('button', grid).forEach((x) => x.classList.remove('sel'));
          b.classList.add('sel');
          url.value = e.url;
          if (!alt.value) alt.value = e.name.replace(/\.[^.]+$/, '');
        });
        grid.append(b);
      }
      const body = el('div', {}, field(t('image.url'), url), field(t('image.alt'), alt),
        el('div', { class: 'muted small' }, images.length ? t('image.pick') : t('image.none')),
        images.length ? grid : null);
      return modal({
        title: t('image.title'), body, ok: t('image.ok'), focus: url,
        onOk: () => {
          if (!safeUrl(url.value, true)) throw new Error(t('image.invalid'));
          const img = doc.createElement('img');
          img.src = url.value.trim();
          img.alt = alt.value.trim();
          range = saved;
          exec('insertHTML', img.outerHTML);
          return true;
        },
      });
    }

    /* Toolbar */

    function button(key, content, action, stateCmd) {
      const b = el('button', { type: 'button', class: 'rte-btn', title: t('rte.' + key), 'aria-label': t('rte.' + key) },
        typeof content === 'string' && content.length === 1 ? el('span', { class: 'rte-glyph-' + content.toLowerCase() }, content) : icon(content));
      b.addEventListener('mousedown', (e) => e.preventDefault()); // keep the editor's selection
      b.addEventListener('click', () => (typeof action === 'function' ? action() : exec(action)));
      if (stateCmd) {
        stateful.push(() => { let on = false; try { on = doc.queryCommandState(stateCmd); } catch (e) { /* ignore */ } b.setAttribute('aria-pressed', String(on)); });
      }
      return b;
    }
    const sep = () => el('span', { class: 'rte-sep', 'aria-hidden': 'true' });

    const block = el('select', { class: 'rte-select', title: t('rte.block'), 'aria-label': t('rte.block') },
      ['p', 'h1', 'h2', 'h3'].map((v) => el('option', { value: v }, t('rte.' + v))));
    block.addEventListener('change', () => exec('formatBlock', '<' + block.value + '>'));
    stateful.push(() => {
      let v = '';
      try { v = String(doc.queryCommandValue('formatBlock')).toLowerCase().replace(/[<>]/g, ''); } catch (e) { /* ignore */ }
      block.value = ['h1', 'h2', 'h3'].includes(v) ? v : 'p';
    });

    const colorInput = el('input', { type: 'color', value: '#c0392b', 'aria-label': t('rte.color') });
    const color = el('label', { class: 'rte-btn rte-color', title: t('rte.color') }, el('span', { class: 'swatch' }, 'A'), colorInput);
    color.style.setProperty('--swatch', colorInput.value);
    colorInput.addEventListener('change', () => {
      color.style.setProperty('--swatch', colorInput.value);
      focusEditor();
      doc.execCommand('styleWithCSS', false, true);
      doc.execCommand('foreColor', false, colorInput.value);
      doc.execCommand('styleWithCSS', false, false);
      saveRange();
      sync();
    });

    bar.append(
      button('undo', 'undo', 'undo'), button('redo', 'redo', 'redo'), sep(),
      block, sep(),
      button('bold', 'B', 'bold', 'bold'), button('italic', 'I', 'italic', 'italic'),
      button('underline', 'U', 'underline', 'underline'), color, sep(),
      button('ul', 'list-ul', 'insertUnorderedList', 'insertUnorderedList'),
      button('ol', 'list-ol', 'insertOrderedList', 'insertOrderedList'), sep(),
      button('left', 'align-left', 'justifyLeft', 'justifyLeft'),
      button('center', 'align-center', 'justifyCenter', 'justifyCenter'),
      button('right', 'align-right', 'justifyRight', 'justifyRight'), sep(),
      button('link', 'link', linkDialog), button('unlink', 'unlink', 'unlink'),
      button('image', 'image', imageDialog), button('hr', 'hr', 'insertHorizontalRule'), sep(),
      button('clear', 'eraser', () => { exec('removeFormat'); exec('formatBlock', '<p>'); }),
    );

    return {
      refresh,
      /** Re-apply the managed prefix without touching the content or selection. */
      decorate() { if (doc) decorate(split(ta.value)[0]); },
      show(on) {
        wrap.hidden = !on;
        ta.hidden = on;
        if (on) refresh();
      },
    };
  }

  /* ---------------------------------------------------------------- */
  /* A folder's public page as Apache renders it (mod_autoindex,       */
  /* FancyIndexing + HTMLTable). Used by both previews.                 */
  /* ---------------------------------------------------------------- */

  const escHtml = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const HTML_EXT = new Set(['html', 'htm', 'xhtml', 'xht']);

  /** A full page around (server-cleaned) HTML; relative links and images resolve against the folder. */
  function pageDoc(bodyHtml, baseUrl, title) {
    return `<!doctype html><html><head><meta charset="utf-8"><base href="${escHtml(baseUrl)}" target="_blank">` +
      `<title>${escHtml(title || '')}</title></head><body>${bodyHtml}</body></html>`;
  }

  /**
   * Open rendered HTML full-size in a new tab. The tab is a tiny wrapper page
   * that holds the HTML in a sandboxed frame without scripts, so user content
   * never runs with the tool's (and the forum's) origin.
   */
  function popOut(html, title) {
    const wrapper = `<!doctype html><html><head><meta charset="utf-8"><title>${escHtml(title || '')}</title>` +
      '<style>html,body{margin:0;height:100%;background:#fff}iframe{display:block;border:0;width:100%;height:100%}</style></head>' +
      `<body><iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="${escHtml(html)}"></iframe></body></html>`;
    const url = URL.createObjectURL(new Blob([wrapper], { type: 'text/html' }));
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  }
  const APACHE_ICONS = { dir: 'folder.gif', image: 'image2.gif', audio: 'sound2.gif', video: 'movie.gif', archive: 'compressed.gif', doc: 'layout.gif', code: 'text.gif', file: 'unknown.gif' };
  const apacheSize = (b) => {
    if (b == null) return '  - ';
    if (b < 1024) return String(b);
    const u = ['K', 'M', 'G', 'T'];
    let i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
    return (b < 10 ? b.toFixed(1) : Math.round(b)) + u[i];
  };

  /** @param L a listing (entries, crumbs, url); header/footer already cleaned by the server */
  function folderPageHtml(L, headerHtml, footerHtml) {
    const esc = escHtml;
    const ICONS = APACHE_ICONS;
      const origin = new URL(state.me.publicUrl).origin;
            const title = 'Index of /' + [state.me.dir].concat(L.crumbs.map((c) => c.name)).join('/');
      const pad = (n) => String(n).padStart(2, '0');
      const ts = (sec) => { const d = new Date(sec * 1000); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
      const rows = L.entries.slice(0, 40).map((e) => {
        const k = e.type === 'dir' ? 'dir' : kindOf(e.name);
        return `<tr><td valign="top"><img src="${origin}/icons/${ICONS[k]}" alt="[ ]"></td><td><a href="#">${esc(e.name)}${e.type === 'dir' ? '/' : ''}</a></td>` +
          `<td align="right">${ts(e.mtime)}  </td><td align="right">${apacheSize(e.size)}</td><td>&nbsp;</td></tr>`;
      }).join('\n');
      const header = headerHtml.trim() ? headerHtml : `<h1>${esc(title)}</h1>`;
      const html = `<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN"><html><head><meta charset="utf-8"><base href="${esc(L.url)}" target="_blank"><title>${esc(title)}</title></head><body>
${header}
<table>
<tr><th valign="top"><img src="${origin}/icons/blank.gif" alt="[ICO]"></th><th><a href="#">Name</a></th><th><a href="#">Last modified</a></th><th><a href="#">Size</a></th><th><a href="#">Description</a></th></tr>
<tr><th colspan="5"><hr></th></tr>
<tr><td valign="top"><img src="${origin}/icons/back.gif" alt="[PARENTDIR]"></td><td><a href="#">Parent Directory</a></td><td>&nbsp;</td><td align="right">  - </td><td>&nbsp;</td></tr>
${rows}
<tr><th colspan="5"><hr></th></tr>
</table>
${footerHtml}
</body></html>`;
    return html;
  }

  const design = (() => {
    const edH = $('#ed-header');
    const edF = $('#ed-footer');
    const frame = $('#preview');
    let saved = { header: '', footer: '' };
    let loadedFor = null;

    const dirty = () => loadedFor !== null && (edH.value !== saved.header || edF.value !== saved.footer);
    const markDirty = () => {
      $('#design-dirty').hidden = !dirty();
      // Nothing on disk and nothing typed: nothing to reset.
      $('#design-reset').disabled = !saved.header && !saved.footer && !edH.value && !edF.value;
    };
    const folderUrl = () => (state.listing ? state.listing.url : state.me ? state.me.publicUrl : '');

    const visualH = visualEditor($('.editor[data-part="header"]'), { onSave: () => save(), baseUrl: folderUrl, split: splitBg });
    const visualF = visualEditor($('.editor[data-part="footer"]'), {
      onSave: () => save(), baseUrl: folderUrl, pageStyle: () => splitBg(edH.value)[0],
    });
    const visuals = [visualH, visualF];
    const refreshVisuals = () => { visuals.forEach((v) => v.refresh()); showBg(); };

    const bgInput = $('#bg-color');
    const bgClear = $('#bg-clear');
    function showBg() {
      const c = bgOf(edH.value);
      bgInput.value = c || BG_DEFAULT;
      bgClear.hidden = !c;
      $('.bg-pick').classList.toggle('unset', !c);
    }
    function setBg(hex) {
      edH.value = bgBlock(hex) + splitBg(edH.value)[1];
      edH.dispatchEvent(new Event('input'));
      visualH.decorate();
      visualF.decorate();
    }
    bgInput.addEventListener('input', () => setBg(bgInput.value.toLowerCase()));
    bgClear.addEventListener('click', () => setBg(''));
    // Hand edits to the block in HTML mode move the picker too.
    edH.addEventListener('input', () => { showBg(); visualF.decorate(); });
    let mode = store.get('pbput-editor', 'visual') === 'code' ? 'code' : 'visual';
    function setMode(m) {
      mode = m;
      store.set('pbput-editor', m);
      for (const b of $$('#design-mode button')) {
        b.classList.toggle('active', b.dataset.mode === m);
        b.setAttribute('aria-checked', String(b.dataset.mode === m));
      }
      for (const v of visuals) v.show(m === 'visual');
      for (const p of $$('[data-help]')) p.hidden = p.dataset.help !== m;
    }
    for (const b of $$('#design-mode button')) b.addEventListener('click', () => setMode(b.dataset.mode));
    setMode(mode);

    async function load() {
      const path = state.path;
      const folderName = path ? path.split('/').pop() : state.me.dir;
      for (const b of $$('.design-folder')) b.textContent = folderName;
      const url = folderUrl();
      $('#design-url').href = url;
      $('#design-url').textContent = url.replace(/^https?:\/\//, '');
      if (loadedFor === path && dirty()) { renderPreview(); return; }
      try {
        const r = await api('layout', null, { path });
        saved = { header: r.header, footer: r.footer };
        edH.value = r.header;
        edF.value = r.footer;
        loadedFor = path;
        $('#index-notice').hidden = !r.hasIndex;
        markDirty();
        refreshVisuals();
        renderPreview();
      } catch (e) { fail(e); }
    }

    async function save() {
      try {
        $('#design-save').disabled = true;
        // The server cleans what it stores; show the editors exactly that.
        const r = await api('save-layout', { path: loadedFor, header: edH.value, footer: edF.value });
        if (r.header !== edH.value || r.footer !== edF.value) {
          edH.value = r.header;
          edF.value = r.footer;
          refreshVisuals();
        }
        saved = { header: r.header, footer: r.footer };
        markDirty();
        loadListing(true);
        if (r.removed.length) toast(t('design.removed', { items: r.removed.join(', ') }), 'error');
        else toast(t('design.saved'));
      } catch (e) { fail(e); } finally { $('#design-save').disabled = false; }
    }


    /** Shows what saving would publish: the header/footer as the server cleans them. */
    let previewToken = 0;
    async function renderPreview() {
      const token = ++previewToken;
      let r;
      try {
        r = await api('preview-layout', { path: loadedFor !== null ? loadedFor : state.path, header: edH.value, footer: edF.value });
      } catch (e) {
        return; // keep the last preview; save reports real errors
      }
      if (token !== previewToken) return;
      listingPreview(r.header, r.footer);
    }

    /** Mimics Apache mod_autoindex (FancyIndexing, HTMLTable) as configured on the server. */
    function listingPreview(headerHtml, footerHtml) {
      frame.srcdoc = folderPageHtml(state.listing || { entries: [], crumbs: [], url: folderUrl() }, headerHtml, footerHtml);
    }

    let previewTimer;
    for (const ed of [edH, edF]) {
      ed.addEventListener('input', () => { markDirty(); clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 400); });
      ed.addEventListener('keydown', (e) => {
        if (e.key === 'Tab' && !e.shiftKey) {
          e.preventDefault();
          const { selectionStart: from, selectionEnd: to } = ed;
          ed.setRangeText('  ', from, to, 'end');
          ed.dispatchEvent(new Event('input'));
        }
      });
    }
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's' && state.view === 'design') { e.preventDefault(); save(); }
    });
    $('#design-save').addEventListener('click', save);

    /** Header or footer on its own, cleaned by the server exactly as it will be published. */
    async function popPart(part) {
      try {
        const r = await api('preview-layout', {
          path: loadedFor !== null ? loadedFor : state.path,
          // The footer is shown on the page background set in the header.
          header: part === 'header' ? edH.value : splitBg(edH.value)[0],
          footer: part === 'footer' ? edF.value : '',
        });
        const file = part === 'header' ? 'HEADER.html' : 'README.html';
        popOut(pageDoc(r.header + r.footer, folderUrl(), file), file);
      } catch (e) { fail(e); }
    }
    for (const b of $$('.pop-btn[data-pop]')) b.addEventListener('click', () => popPart(b.dataset.pop));
    $('#design-pop').addEventListener('click', () => popOut(frame.srcdoc, t('design.preview')));
    /** Delete HEADER.html and README.html: the folder goes back to Apache's plain listing. */
    $('#design-reset').addEventListener('click', () => {
      const folder = loadedFor ? loadedFor.split('/').pop() : state.me.dir;
      return modal({
        title: t('design.reset_title', { folder }),
        text: t('design.reset_text'),
        ok: t('design.reset_ok'),
        danger: true,
        onOk: async () => {
          // Saving empty content deletes the files server-side.
          await api('save-layout', { path: loadedFor, header: '', footer: '' });
          saved = { header: '', footer: '' };
          edH.value = '';
          edF.value = '';
          markDirty();
          refreshVisuals();
          renderPreview();
          loadListing(true);
          toast(t('design.reset_done'));
          return true;
        },
      });
    });

    $('#design-revert').addEventListener('click', () => {
      edH.value = saved.header;
      edF.value = saved.footer;
      markDirty();
      refreshVisuals();
      renderPreview();
    });

    return { load, dirty };
  })();

  /* ---------------------------------------------------------------- */
  /* Start                                                            */
  /* ---------------------------------------------------------------- */

  (async () => {
    try {
      state.me = await api('me');
      renderMe();
      if (boot.error) toast(boot.error, 'error');
      await route();
    } catch (e) { fail(e); }
  })();
})();
