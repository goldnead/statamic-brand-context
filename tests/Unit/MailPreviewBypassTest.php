<?php

use Goldnead\BrandContext\Simulation\MailPreview;

/*
 * Ways around the first layer of MailPreview that a reviewer found (22.10.2026 run, 21.10. session): each one
 * was only held by the policy of the frame before. The first layer must hold on its own as well.
 */

it('blocks a source whichever element wears it, and counts the image', function (string $markup, int $blocked) {
    $out = MailPreview::prepare('<p>x</p>'.$markup);

    expect($out['blocked_images'])->toBe($blocked)
        ->and($out['html'])->not->toMatch('/\s(src|lowsrc|dynsrc|srcset)="/');
})->with([
    'image tag, which the parser turns into img' => ['<image src="https://e.test/pixel.gif">', 1],
    'input without type image' => ['<input src="https://e.test/p.gif">', 1],
    'input type image' => ['<input type="image" src="https://e.test/p.gif">', 1],
    'lowsrc and dynsrc' => ['<img lowsrc="https://e.test/a" dynsrc="https://e.test/b">', 1],
    'picture with source goes with its source' => ['<picture><source srcset="https://e.test/a.webp"><img src="https://e.test/a.png"></picture>', 0],
    'a script src is gone with the script' => ['<script src="https://e.test/x.js"></script>', 0],
]);

it('removes the elements that are fetches of their own', function (string $markup) {
    expect(strtolower(MailPreview::prepare('<p>x</p>'.$markup)['html']))->not->toMatch('/<(fencedframe|portal|bgsound|keygen)/');
})->with([
    'fencedframe' => ['<fencedframe src="https://e.test/f"></fencedframe>'],
    'portal' => ['<portal src="https://e.test/p"></portal>'],
    'bgsound' => ['<bgsound src="https://e.test/s.mid">'],
    'keygen' => ['<keygen name="k">'],
]);

it('does not trust a css value that is written to hide a fetch from a pattern', function (string $style) {
    $out = MailPreview::prepare('<div style="'.$style.'">Text</div><style>'.$style.'</style>')['html'];

    expect($out)->not->toContain('e.test')
        ->and($out)->toContain('Text');
})->with([
    'escaped u' => ['background:\75rl(https://e.test/a)'],
    'escaped r' => ['background:u\72l(https://e.test/a)'],
    'comment in the name' => ['background:url/**/(https://e.test/a)'],
    'image-set with a string' => ['background-image:image-set(\'https://e.test/a\' 1x)'],
    'src function' => ['content:src(\'https://e.test/a\')'],
    'escaped import' => ['@\69mport "https://e.test/x.css";'],
]);

it('still cuts an ordinary url() and an import', function () {
    $out = MailPreview::prepare('<div style="background:url(https://e.test/a)">x</div><style>@import url(https://e.test/x.css); p{color:red}</style>')['html'];

    expect($out)->not->toContain('e.test')
        ->and($out)->toContain('color:red');
});

it('does not eat the rules behind an import that has no semicolon', function () {
    $out = MailPreview::prepare("<style>@import url(a.css)\np{color:red}</style>")['html'];

    expect($out)->toContain('color:red');
});

it('reads an address as a browser does: control characters in the scheme change nothing', function (string $href) {
    $out = MailPreview::prepare('<a href="'.$href.'">Klick</a>');

    expect($out['links'])->toBe([])
        ->and($out['html'])->toContain('href="#"')
        ->and($out['html'])->not->toContain('data-sim-href');
})->with([
    'tab in the scheme' => ["java\tscript:alert(1)"],
    'newline in the scheme' => ["java\nscript:alert(1)"],
    'control character before it' => ["\x01javascript:alert(1)"],
    'leading spaces' => ['   javascript:alert(1)'],
    'protocol relative' => ['//evil.test/x'],
]);

it('remembers a link with the control characters taken out of it', function () {
    $out = MailPreview::prepare("<a href=\"  https://site.test/ku\trs  \">Kurs</a>");

    expect($out['links'])->toBe([['text' => 'Kurs', 'url' => 'https://site.test/kurs']]);
});
