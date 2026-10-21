<?php

use Goldnead\BrandContext\Simulation\MailPreview;

/*
 * The mail of a dry run is looked at, never delivered. Looking must not run a script, fetch an
 * image (a tracking pixel records "opened"), or lead anywhere (a tracking link records "clicked").
 */

it('keeps the look of an ordinary mail', function () {
    $out = MailPreview::prepare('<table><tr><td><h1>Willkommen, Müller</h1><p>Schön, dass <b>du</b> da bist.</p></td></tr></table>');

    expect($out['html'])->toContain('<table>')
        ->and($out['html'])->toContain('Willkommen, Müller')
        ->and($out['html'])->toContain('<b>du</b>')
        ->and($out['links'])->toBe([])
        ->and($out['blocked_images'])->toBe(0);
});

it('removes scripts, frames, objects and the things that load on their own', function (string $markup) {
    $html = MailPreview::prepare('<p>Text</p>'.$markup)['html'];

    expect(strtolower($html))->not->toContain('<script')
        ->and(strtolower($html))->not->toContain('<iframe')
        ->and(strtolower($html))->not->toContain('<object')
        ->and(strtolower($html))->not->toContain('<embed')
        ->and(strtolower($html))->not->toContain('<base')
        ->and(strtolower($html))->not->toContain('<meta')
        ->and(strtolower($html))->not->toContain('<link')
        ->and(strtolower($html))->not->toContain('<svg')
        ->and($html)->toContain('Text');
})->with([
    'script' => ['<script>alert(1)</script>'],
    'upper case script' => ['<SCRIPT SRC="https://evil.test/x.js"></SCRIPT>'],
    'broken script' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
    'iframe' => ['<iframe src="https://evil.test"></iframe>'],
    'object' => ['<object data="https://evil.test/x.swf"></object>'],
    'embed' => ['<embed src="https://evil.test/x">'],
    'base' => ['<base href="https://evil.test/">'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">'],
    'stylesheet link' => ['<link rel="stylesheet" href="https://evil.test/x.css">'],
    'svg with script' => ['<svg onload="alert(1)"><script>alert(1)</script></svg>'],
    'conditional comment' => ['<!--[if mso]><script>alert(1)</script><![endif]-->'],
]);

it('drops the code of a script, not just its tag', function () {
    expect(MailPreview::prepare('<p>A</p><script>alert(document.cookie)</script><p>B</p>')['html'])->toBe('<p>A</p><p>B</p>');
});

it('drops event attributes and attributes that load or navigate', function () {
    $html = MailPreview::prepare(
        '<p onclick="alert(1)" onmouseover="x()">A</p><img src="https://t.example/p.gif" onerror="alert(1)" srcset="https://t.example/a 2x">'
        .'<form action="https://evil.test"><input type="submit" formaction="https://evil.test"></form><table background="https://t.example/bg.png"></table>'
    )['html'];

    expect($html)->not->toContain('onclick')
        ->and($html)->not->toContain('onmouseover')
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('srcset')
        ->and($html)->not->toContain('formaction')
        ->and($html)->not->toContain('action=')
        ->and($html)->not->toContain('background=');
});

it('blocks every image and remembers where it pointed, so a tracking pixel is never fetched', function () {
    $out = MailPreview::prepare('<p>Hi</p><img src="https://track.example/open.gif?u=1" width="1" height="1"><img src="/logo.png" alt="Logo">');

    expect($out['blocked_images'])->toBe(2)
        ->and($out['html'])->not->toContain(' src=')
        ->and($out['html'])->toContain('data-sim-src="https://track.example/open.gif?u=1"')
        ->and($out['html'])->toContain('alt="Logo"');
});

it('keeps a link as text and as data and points it nowhere', function () {
    $out = MailPreview::prepare('<a href="https://track.example/c/abc123?to=https%3A%2F%2Fsite.test" target="_blank" rel="noopener">Jetzt starten</a> <a href="mailto:hallo@site.test">Schreib uns</a> <a href="/kurs">Zum Kurs</a>');

    expect($out['links'])->toBe([
        ['text' => 'Jetzt starten', 'url' => 'https://track.example/c/abc123?to=https%3A%2F%2Fsite.test'],
        ['text' => 'Schreib uns', 'url' => 'mailto:hallo@site.test'],
        ['text' => 'Zum Kurs', 'url' => '/kurs'],
    ])
        ->and($out['html'])->not->toContain('target=')
        ->and($out['html'])->toContain('href="#"')
        ->and($out['html'])->not->toMatch('/\shref="(?!#")/')
        ->and($out['html'])->toContain('data-sim-href="mailto:hallo@site.test"');
});

it('does not remember or keep a link with a scheme that runs code', function (string $href) {
    $out = MailPreview::prepare('<a href="'.$href.'">Klick</a>');

    expect($out['links'])->toBe([])
        ->and($out['html'])->toContain('href="#"')
        ->and(strtolower($out['html']))->not->toContain('javascript')
        ->and(strtolower($out['html']))->not->toContain('data:text');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'mixed case' => ['JaVaScRiPt:alert(1)'],
    'data url' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
    'vbscript' => ['vbscript:msgbox(1)'],
]);

it('cuts url() and @import out of css, in blocks and in style attributes', function () {
    $out = MailPreview::prepare(
        '<style>@import url("https://evil.test/x.css"); .a{background:url(https://t.example/bg.png) no-repeat; color:red} .b{behavior:url(x.htc)}</style>'
        .'<div style="background-image:url(\'https://t.example/pixel\');color:blue">Text</div>'
    )['html'];

    expect($out)->not->toContain('@import')
        ->and($out)->not->toContain('url(')
        ->and($out)->not->toContain('evil.test')
        ->and($out)->not->toContain('t.example')
        ->and($out)->toContain('color:red')
        ->and($out)->toContain('color:blue');
});

it('survives empty and broken markup', function () {
    expect(MailPreview::prepare(''))->toBe(['html' => '', 'links' => [], 'blocked_images' => 0])
        ->and(MailPreview::prepare('   ')['html'])->toBe('')
        ->and(MailPreview::prepare('<p>offen <b>und <i>schief</p>')['html'])->toContain('offen');
});

it('frames the mail with a policy that allows nothing but inline styles and data images', function () {
    // The policy sits in an attribute, where the quotes are entities; a browser reads them back.
    $doc = html_entity_decode(MailPreview::frame('<p>Hi</p>'), ENT_QUOTES);

    expect($doc)->toContain("default-src 'none'")
        ->and($doc)->toContain('img-src data:')
        ->and($doc)->toContain("style-src 'unsafe-inline'")
        ->and($doc)->toContain("form-action 'none'")
        ->and($doc)->toContain("base-uri 'none'")
        ->and($doc)->toContain('<p>Hi</p>')
        ->and($doc)->not->toContain('<script');
});

it('keeps umlauts and other non-ASCII text', function () {
    expect(MailPreview::prepare('<p>Größe, Ärger, „Zitat“, 日本語, 😀</p>')['html'])->toContain('Größe, Ärger, „Zitat“, 日本語, 😀');
});
