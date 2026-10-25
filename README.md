# pbput: web uploads for phpBB communities

A small, dependency-free PHP app (PHP 7.4+, tested on 8.4) for replacing FTP
uploads on a phpBB forum. Users sign in with their phpBB account and manage
files in their own folder, published at `https://files.example.com/<username>/`.

* **Sign-in:** reuses the forum login cookie (`<cookie_name>_u/_sid/_k`) when
  present, validated the same way phpBB's `session_begin()` does (expiry,
  browser/IP checks, "remember me" keys). Otherwise there's a login form that
  checks the password against phpBB's users table, supporting all hash formats
  phpBB uses (`$H$`, `$CP$`, `$H\2y$`, bcrypt, argon2). Only `SELECT`s are issued.
* **Files:** drag-and-drop (including whole folders), chunked and resumable
  uploads (works under PHP's 2 MB `upload_max_filesize`), new folder, rename,
  move (drag rows onto folders), delete, copy link, copy BBCode for forum
  posts (`[img]` for jpg/png/gif/webp, `[url]` otherwise; one line per item when
  several are selected), filter and sort, thumbnails.
* **Page design:** edits `HEADER.html` / `README.html` per folder, either in a
  visual editor (headings, bold/italic/underline, colour, lists, alignment,
  links, images from the folder, horizontal line) or as raw HTML. The visual
  editor runs in a sandboxed iframe without scripts, cleans pasted content
  down to basic tags, and only rewrites the HTML when the user edits there, so
  hand-written code survives switching modes.
* **Page background:** a colour picker on the header pane (starts at dark grey
  `#1e1e1e`). It is stored as one managed block at the top of `HEADER.html`,
  `<style id="pbput-bg">body{background-color:…}</style>`; dark colours also
  set light text and link colours so Apache's listing stays readable.
* **Reset page:** deletes both `HEADER.html` and `README.html` in the current
  folder (after a confirmation), returning it to Apache's plain listing. Apache's
  global `mod_autoindex` config (`HeaderName HEADER.html`, `ReadmeName
  README.html`) shows them above and below the listing. A live preview is
  included. No `.htaccess` needed (works with `AllowOverride None`).
* Light/dark mode (follows the OS, toggle remembered per browser), mobile layout.
* **Languages:** Swedish and English. Signed-in users get the language from
  their phpBB profile (`user_lang`), re-read every few minutes. The login page
  uses, in order: the language of the last sign-in in that browser (cookie),
  the browser's `Accept-Language`, then `default_lang` from `config.php`.

## Installation

1. Copy the app to a directory served by the forum's web host, e.g.
   `https://www.example.com/upload/` (set `base_path` to match).
2. Copy `config.sample.php` to `config.php` and set at least `phpbb_root`,
   `base_path`, `storage_root` and `public_base_url`. Every key is optional and
   falls back to the defaults in `lib/bootstrap.php`.
3. Make `storage_root` and `var_dir` writable by the web server user. `var_dir`
   must not be web accessible.
4. Serve `storage_root` from its own host (e.g. `files.example.com`) and apply
   the Apache snippets in `server/` (see [Security model](#security-model)).

`config.php` is ignored by git; never commit it.

## Translations

Strings live in `lang/<code>.php`, one flat array per language, shared by the
PHP pages, API error messages and the JavaScript (embedded in the page at load).
Placeholders look like `{name}`; plural forms use `key.one` / `key.other`,
picked by the `{n}` argument. To add a language, copy `lang/en.php` to e.g.
`lang/de.php`, translate it, and set `_locale` (used for dates and numbers).
Missing keys fall back to English. A language is offered as soon as its file
exists; phpBB codes like `en_us` are matched on their first two letters.

## Layout

```
index.php         page (login form or app shell)
api.php           JSON API used by assets/app.js
lib/              phpBB access, auth, storage, i18n (guarded against direct requests)
lang/             translations (en.php, sv.php)
assets/           CSS, JS, icons; no build step
server/           Apache config snippets for the forum and user-content hosts
config.php        site config (not in this repo; see config.sample.php)
deploy.sample.sh  rsync deploy script template
```

## Deploying

Copy `deploy.sample.sh` to `deploy.sh` (ignored by git) or run it directly
with the target set in the environment:

```sh
TARGET=user@server:/path/to/pbput ./deploy.sample.sh
```

It syncs only the app files (never `.git`, `config.php`, docs), keeps the
existing files' `www-data` ownership and lints the PHP on the server.

## Folder mapping

A user owns the folder under `storage_root` whose name matches their username
case-insensitively. Latin-1 names left over from FTP (e.g. a folder named with a
raw `\xD6` byte) are matched too. If several match (e.g. `alice` and `Alice`),
the user can switch between them from the user menu. If none exists, one is
created on first sign-in. `reserved_dirs` lists folders that no current account
matches, so a new registration can't claim them. Use `dir_overrides`
(user_id → folder) to hand one out or to map renamed accounts.

## Security model

The user-content host shares files; it is not a web host. Layers, outermost
first:

**Server (needs root, see `server/`):**
* Disable PHP for the user area (`php_admin_flag engine off`) and retire any
  FTP access to it (FTP bypasses every check below). Give pbput a dedicated
  read-only database account if possible.
* `server/user-content.conf.snippet`: every HTML/XML/SVG page there gets a CSP that
  allows **no scripts**, no forms, no framing; plus `nosniff`. Needs
  `a2enmod headers`. Existing pages keep displaying, but their scripts stop.
* `server/forum.conf.snippet`: `X-Frame-Options`, one canonical host, then host-only
  `Secure` phpBB cookies, so forum sessions never reach the user-content
  host. Ideally, serve user content from its own registrable domain.

**The tool (enforced server-side; the browser does the heavy lifting only):**
* No new web content: `.html .htm .xhtml .mht .hta .js .mjs .swf .svg .xsl`
  (and server code) are refused on upload and rename, any extension position.
* Header/footer HTML is cleaned on save and preview (`lib/Sanitizer.php`,
  PHP 8.4 HTML5 parser, allowlist): formatting, links (http/https/mailto),
  images, tables, media and CSS stay; scripts, frames, plugins, forms, `on*`
  handlers, `javascript:`/`data:` URLs, `@import` and script-capable CSS go.
  The editor shows what was removed.
* Tool cookies use the `__Host-` prefix (cannot be set from the user-content
  host); the API refuses requests whose `Sec-Fetch-Site` is not same-origin,
  sends `Cross-Origin-Resource-Policy: same-origin`, and still requires the
  CSRF token for every change.

Other notes:
* Folders the web server user cannot write to (e.g. owned by an old FTP user)
  are shown as read-only in the UI. Fix with `chgrp -R www-data … && chmod -R
  g+w …` (prefer `2775` over `777`).
* Raise `upload_max_filesize`/`post_max_size` (e.g. 16M/20M) for fewer,
  larger chunks. Optional; uploads already work.
