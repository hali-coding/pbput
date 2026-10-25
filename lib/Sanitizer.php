<?php
declare(strict_types=1);

defined('PBPUT_ROOT') || exit;

/**
 * Allowlist HTML cleaner for user-written page content (header, footer).
 *
 * Users keep rich formatting, links, images, tables, media and CSS; anything
 * active is removed: scripts, frames, plugins, forms, event handlers,
 * javascript:/data: URLs and script-capable CSS. Uses PHP 8.4's HTML5 parser
 * when available, so content is parsed the way browsers parse it.
 */
final class Sanitizer
{
    /** Removed together with their content. */
    const DROP = [
        'script', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'portal',
        'form', 'input', 'button', 'select', 'option', 'optgroup', 'textarea', 'datalist', 'output',
        'base', 'link', 'meta', 'title', 'head', 'svg', 'math', 'xml',
    ];

    /** Allowed elements and their extra attributes (on top of GLOBAL). */
    const TAGS = [
        'p' => [], 'br' => [], 'hr' => ['size', 'width', 'noshade'], 'div' => [], 'span' => [], 'center' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [],
        'sub' => [], 'sup' => [], 'small' => [], 'big' => [], 'mark' => [], 'abbr' => [], 'cite' => [], 'q' => [],
        'blockquote' => [], 'pre' => [], 'code' => [], 'kbd' => [], 'samp' => [], 'address' => [], 'time' => ['datetime'],
        'font' => ['color', 'size', 'face'],
        'ul' => ['type'], 'ol' => ['start', 'type', 'reversed'], 'li' => ['value'], 'dl' => [], 'dt' => [], 'dd' => [],
        'a' => ['href', 'target', 'rel', 'name'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'border'],
        'figure' => [], 'figcaption' => [], 'picture' => [], 'details' => ['open'], 'summary' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width', 'bgcolor'], 'caption' => [],
        'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => ['valign', 'bgcolor'], 'col' => ['span', 'width'],
        'colgroup' => ['span'], 'th' => ['colspan', 'rowspan', 'valign', 'width', 'bgcolor', 'scope'],
        'td' => ['colspan', 'rowspan', 'valign', 'width', 'bgcolor'],
        'video' => ['src', 'controls', 'poster', 'width', 'height', 'loop', 'muted', 'preload', 'playsinline'],
        'audio' => ['src', 'controls', 'loop', 'muted', 'preload'], 'source' => ['src', 'type', 'media'],
        'style' => ['media'],
    ];
    const GLOBAL = ['class', 'id', 'title', 'lang', 'dir', 'style', 'align'];
    const URL_ATTRS = ['href', 'src', 'poster'];

    /** @var array<string, true> */
    private $removed = [];

    /** @return array{0: string, 1: string[]} cleaned HTML and what was removed */
    public static function clean(string $html): array
    {
        if (trim($html) === '') {
            return ['', []];
        }
        $s = new self();
        if (class_exists('Dom\\HTMLDocument')) {
            $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body></body></html>', LIBXML_NOERROR);
            $root = $doc->createElement('div');
            $doc->body->appendChild($root);
            $root->innerHTML = $html; // fragment parsed in body context, like a browser
            $s->walk($root);
            $out = $root->innerHTML;
        } else {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="pbput-root">' . $html . '</div></body></html>', LIBXML_NONET);
            $doc->encoding = 'UTF-8';
            $root = $doc->getElementById('pbput-root');
            $s->walk($root);
            $out = '';
            foreach (iterator_to_array($root->childNodes) as $c) {
                $out .= $doc->saveHTML($c);
            }
        }
        return [$out, array_keys($s->removed)];
    }

    private function note(string $what): void
    {
        $this->removed[$what] = true;
    }

    /** @param \DOMNode|\Dom\Node $node */
    private function walk($node): void
    {
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower((string)$child->localName);
                if (in_array($tag, self::DROP, true)) {
                    $this->note("<$tag>");
                    $node->removeChild($child);
                } elseif (!isset(self::TAGS[$tag])) {
                    // Unknown or legacy markup (<o:p>, <nobr>…): keep its content.
                    $this->walk($child);
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                } else {
                    $this->attributes($child, $tag);
                    if ($tag === 'style') {
                        if (stripos((string)$child->textContent, '@import') !== false) {
                            $this->note('@import');
                        }
                        $css = self::css((string)$child->textContent, true);
                        if ($css === null) {
                            $this->note('<style>');
                            $node->removeChild($child);
                        } else {
                            $child->textContent = $css;
                        }
                    } else {
                        $this->walk($child);
                    }
                }
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                $node->removeChild($child); // comments, processing instructions, CDATA
            }
            $child = $next;
        }
    }

    /** @param \DOMElement|\Dom\Element $el */
    private function attributes($el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL, self::TAGS[$tag]);
        $names = [];
        foreach ($el->attributes as $a) {
            $names[] = $a->name;
        }
        foreach ($names as $name) {
            $lname = strtolower($name);
            $value = (string)$el->getAttribute($name);
            if (strncmp($lname, 'on', 2) === 0) {
                $this->note($lname);
                $el->removeAttribute($name);
            } elseif (!in_array($lname, $allowed, true) && strncmp($lname, 'data-', 5) !== 0) {
                $el->removeAttribute($name);
            } elseif (in_array($lname, self::URL_ATTRS, true) && !self::url($value, $lname === 'href')) {
                $this->note($lname . '="' . self::scheme($value) . ':…"');
                $el->removeAttribute($name);
            } elseif ($lname === 'style') {
                $css = self::css($value, false);
                if ($css === null) {
                    $this->note('style="…"');
                    $el->removeAttribute($name);
                }
            } elseif ($lname === 'target' && $value !== '_blank' && $value !== '_self') {
                $el->removeAttribute($name);
            }
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function scheme(string $v): string
    {
        return preg_match('/^([a-z][a-z0-9+.\-]*):/i', self::squash($v), $m) ? strtolower($m[1]) : '';
    }

    /** Browsers ignore control characters and whitespace in schemes ("java\tscript:"). */
    private static function squash(string $v): string
    {
        return (string)preg_replace('/[\x00-\x20\x7f]+/', '', $v);
    }

    /** http(s) and relative URLs; mailto: for links. No javascript:, data:, vbscript:… */
    public static function url(string $v, bool $link): bool
    {
        $scheme = self::scheme($v);
        if ($scheme === '') {
            return true;
        }
        return in_array($scheme, $link ? ['http', 'https', 'mailto'] : ['http', 'https'], true);
    }

    /**
     * CSS is allowed (it is how people style their pages) but nothing that can
     * run code or pull in other stylesheets. Returns null when unsafe.
     */
    public static function css(string $css, bool $block): ?string
    {
        // Undo CSS escapes before checking, so "\6a avascript:" is caught too.
        $plain = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function ($m) {
            $cp = hexdec($m[1]);
            return $cp > 0 && $cp < 0x110000 ? mb_chr((int)$cp, 'UTF-8') : '';
        }, $css);
        $plain = (string)preg_replace('/\\\\(.)/s', '$1', (string)$plain);
        if ($block) {
            $plain = (string)preg_replace('/@import[^;]*;?/i', '', $plain);
        } elseif (stripos($plain, '@import') !== false) {
            return null;
        }
        if (preg_match('/expression\s*\(|javascript:|vbscript:|-moz-binding|behavior\s*:|@charset|<\/?style/i', $plain)) {
            return null;
        }
        if (preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', $plain, $m)) {
            foreach ($m[2] as $u) {
                if (!self::url($u, false)) {
                    return null;
                }
            }
        }
        return $plain;
    }
}
