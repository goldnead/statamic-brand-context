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
    ];

    /** Attributes that load or navigate on their own. */
    private const DROP_ATTRIBUTES = ['srcdoc', 'srcset', 'formaction', 'action', 'background', 'poster', 'ping', 'xlink:href', 'data', 'codebase', 'usemap'];

    /** Schemes a link may be remembered with. Anything else (javascript:, data:, vbscript:) is dropped. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

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
            } elseif ($tag === 'img' || $tag === 'input') {
                $blocked += self::blockImage($element);
            } elseif ($tag === 'style') {
                $element->textContent = self::css($element->textContent);
            }

            if ($element->hasAttribute('style')) {
                $element->setAttribute('style', self::css($element->getAttribute('style')));
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
        $target = trim($element->getAttribute('href'));

        $element->removeAttribute('target');
        $element->removeAttribute('rel');
        $element->setAttribute('href', '#');

        if ($target === '' || $target === '#') {
            return;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);

        // A relative link is a link of the site; anything with another scheme is not one at all.
        if (is_string($scheme) && ! in_array(strtolower($scheme), self::LINK_SCHEMES, true)) {
            return;
        }

        $element->setAttribute('data-sim-href', $target);
        $text = trim(preg_replace('/\s+/', ' ', $element->textContent) ?? '');

        $links[] = ['text' => $text !== '' ? mb_strimwidth($text, 0, 120, '…') : $target, 'url' => $target];
    }

    private static function blockImage(DOMElement $element): int
    {
        if (strtolower($element->tagName) === 'input' && strtolower($element->getAttribute('type')) !== 'image') {
            return 0;
        }

        $src = $element->getAttribute('src');
        $element->removeAttribute('src');

        if ($src === '') {
            return 0;
        }

        $element->setAttribute('data-sim-src', $src);
        $element->setAttribute('data-sim-blocked', '1');

        return 1;
    }

    /** CSS with nothing in it that fetches: `url(...)` becomes `none`, `@import` goes, legacy script hooks go. */
    private static function css(string $css): string
    {
        $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? '';
        $css = preg_replace('/url\s*\([^)]*\)/i', 'none', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/i', 'none', $css) ?? '';
        $css = preg_replace('/(behavior|-moz-binding)\s*:[^;}]*/i', '', $css) ?? '';

        return $css;
    }
}
