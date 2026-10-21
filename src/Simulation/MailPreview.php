<?php

namespace Goldnead\BrandContext\Simulation;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Makes the HTML of a mail safe to look at in the dry run ("Probelauf").
 *
 * The mail is looked at, not delivered. Looking must not do anything: no script runs, nothing is
 * fetched (a tracking pixel in a mail is a request that records "opened", and a click-tracking link
 * records "clicked"), no link leads anywhere. Two layers carry that, and neither is trusted alone:
 *
 * 1. {@see prepare()} takes the markup apart: scripts, frames, objects, forms' actions, event
 *    attributes, `<base>` and meta refresh are removed; every link is kept as text with its target
 *    remembered (`data-sim-href`) but points nowhere (`href="#"`); every image is blocked and its
 *    source remembered (`data-sim-src`); `url()` and `@import` in CSS are cut.
 * 2. {@see frame()} wraps the result as a whole document with a Content-Security-Policy that allows
 *    nothing but inline styles and data: images, to be shown in an iframe with an empty `sandbox`
 *    attribute: no script, no same-origin, no top navigation, no forms, no popups.
 *
 * The links are returned as data, so a screen can offer "click this link" as a choice of the person
 * in the simulation instead of a real click.
 */
final class MailPreview
{
    /** Elements that are removed with everything inside. */
    private const REMOVE = [
        'script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'link', 'meta',
        'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'template', 'title', 'head',
        'fencedframe', 'portal', 'bgsound', 'keygen',
    ];

    /** Attributes that load or navigate on their own. */
    private const DROP_ATTRIBUTES = ['srcdoc', 'srcset', 'formaction', 'action', 'background', 'poster', 'ping', 'xlink:href', 'data', 'codebase', 'usemap', 'longdesc', 'manifest'];

    /** Attributes that point a resource at the element: whichever element wears them, they are not loaded. */
    private const SOURCE_ATTRIBUTES = ['src', 'lowsrc', 'dynsrc'];

    /**
     * @return array{html: string, links: list<array{text: string, url: string}>, blocked_images: int}
     */
    public static function prepare(string $html): array
    {
        $links = [];
        $blocked = 0;

        if (trim($html) === '') {
            return ['html' => '', 'links' => [], 'blocked_images' => 0];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        // The prefix makes libxml read the markup as UTF-8 instead of Latin-1.
        $document->loadHTML('<?xml encoding="UTF-8"><div data-sim-root>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        foreach (self::REMOVE as $name) {
            foreach (iterator_to_array($xpath->query('//'.$name) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Comments can hold conditional code for old mail clients; they show nothing.
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            self::cleanAttributes($element);

            $tag = strtolower($element->tagName);

            if ($tag === 'a' || $tag === 'area') {
                self::neutralizeLink($element, $links);
            } elseif ($tag === 'style') {
                $element->textContent = (string) self::css($element->textContent, true);
            }

            // Not by tag name: the parser turns `<image>` into `<img>`, and whatever else carries a source is a fetch.
            $blocked += self::blockSources($element);

            if ($element->hasAttribute('style')) {
                $css = self::css($element->getAttribute('style'), false);

                if ($css === null) {
                    $element->removeAttribute('style');
                } else {
                    $element->setAttribute('style', $css);
                }
            }
        }

        $root = $xpath->query('//div[@data-sim-root]')->item(0);
        $out = '';

        if ($root instanceof DOMNode) {
            foreach ($root->childNodes as $child) {
                $out .= $document->saveHTML($child);
            }
        }

        return ['html' => $out, 'links' => $links, 'blocked_images' => $blocked];
    }

    /**
     * The whole document to hand to an iframe as `srcdoc`, shown with `sandbox=""`.
     */
    public static function frame(string $preparedHtml): string
    {
        $csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; form-action 'none'; base-uri 'none'";
        // A mail is designed for white, whatever theme the screen around it wears.
        $color = '#111827';
        $bg = '#ffffff';

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta http-equiv="Content-Security-Policy" content="'.htmlspecialchars($csp, ENT_QUOTES).'">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<style>html{background:'.$bg.'}body{margin:0;padding:16px;font:14px/1.5 system-ui,sans-serif;color:'.$color.';word-wrap:break-word}'
            .'a{cursor:default}img[data-sim-blocked]{display:inline-block;min-width:24px;min-height:16px;background:rgba(127,127,127,.15)}</style>'
            .'</head><body>'.$preparedHtml.'</body></html>';
    }

    private static function cleanAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (str_starts_with($name, 'on') || in_array($name, self::DROP_ATTRIBUTES, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /** @param  list<array{text: string, url: string}>  $links */
    private static function neutralizeLink(DOMElement $element, array &$links): void
    {
        // A browser throws tabs, newlines and control characters out of an address before it reads the scheme
        // (`java<tab>script:` is `javascript:`): so does this, before it decides.
        $target = preg_replace('/[\x00-\x20\x7f]+/', '', $element->getAttribute('href')) ?? '';

        $element->removeAttribute('target');
        $element->removeAttribute('rel');
        $element->setAttribute('href', '#');

        if ($target === '' || $target === '#') {
            return;
        }

        // Only an address a person could follow is remembered: a scheme from the list, or a path of the site.
        if (! preg_match('~^(https?:|mailto:|tel:|/(?!/))~i', $target)) {
            return;
        }

        $element->setAttribute('data-sim-href', $target);
        $text = trim(preg_replace('/\s+/', ' ', $element->textContent) ?? '');

        $links[] = ['text' => $text !== '' ? mb_strimwidth($text, 0, 120, '…') : $target, 'url' => $target];
    }

    /**
     * Takes the source off an element and remembers it. Counts one blocked image per element that had one.
     */
    private static function blockSources(DOMElement $element): int
    {
        $blocked = 0;

        foreach (self::SOURCE_ATTRIBUTES as $name) {
            if (! $element->hasAttribute($name)) {
                continue;
            }

            $value = $element->getAttribute($name);
            $element->removeAttribute($name);

            if ($value !== '' && $blocked === 0) {
                $element->setAttribute('data-sim-src', $value);
                $element->setAttribute('data-sim-blocked', '1');
                $blocked = 1;
            }
        }

        return $blocked;
    }

    /**
     * CSS with nothing in it that fetches. `url()` becomes `none`, `@import` goes. Anything written in a way that
     * hides a fetch from a pattern (a backslash escape, a comment inside a function name, `image-set()` with a
     * string, `src()`) is not parsed but dropped: a style attribute disappears (null), a style block is emptied.
     */
    private static function css(string $css, bool $block): ?string
    {
        if (preg_match('/\\\\|\/\*|image-set|image\s*\(|(?<![\w-])src\s*\(|-webkit-image|cross-fade|element\s*\(/i', $css)) {
            return $block ? '' : null;
        }

        $css = preg_replace('/@import\s[^;\n}]*;?/i', '', $css) ?? '';
        $css = preg_replace('/url\s*\([^)]*\)/i', 'none', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/i', 'none', $css) ?? '';
        $css = preg_replace('/(behavior|-moz-binding)\s*:[^;}]*/i', '', $css) ?? '';

        return $css;
    }
}
