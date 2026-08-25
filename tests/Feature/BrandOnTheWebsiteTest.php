<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Http\Middleware\SetBrandForSite;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * A visitor has no session, so nothing told a website request which brand it
 * belonged to — and the scope fails closed. The result was not an error: it was
 * an empty website. Every page rendered, every query returned nothing, and no
 * log line was written. It took a full inventory of a demo installation to
 * notice, because "no content" and "correctly no content" look identical.
 *
 * These tests exist so it cannot happen again quietly. The last one is the most
 * important: the fallback has to be audible.
 */
beforeEach(function () {
    $this->enableMultiBrand();

    $this->nord = Brand::factory()->create(['handle' => 'nord', 'name' => 'Nord']);
    $this->sued = Brand::factory()->create(['handle' => 'sued', 'name' => 'Süd']);

    BrandContext::runFor($this->nord, fn () => Widget::create(['email' => 'n@example.com', 'token' => 'n']));
    BrandContext::runFor($this->sued, fn () => Widget::create(['email' => 's@example.com', 'token' => 's']));

    config()->set('brand-context.default_handle', 'nord');

    Route::middleware(SetBrandForSite::class)->get('/seite', function () {
        return response()->json([
            'marke' => app('brand-context')->current()?->handle,
            'sichtbar' => Widget::pluck('email')->all(),
        ]);
    });
});

it('serves a brand its own rows when the host names it', function () {
    config()->set('brand-context.hosts', ['sued.example' => 'sued']);

    $this->get('http://sued.example/seite')
        ->assertOk()
        ->assertJson(['marke' => 'sued', 'sichtbar' => ['s@example.com']]);
});

it('ignores the port, so a local address matches the live one', function () {
    config()->set('brand-context.hosts', ['sued.example' => 'sued']);

    $this->get('http://sued.example:8099/seite')->assertJson(['marke' => 'sued']);
});

it('treats www as the same brand rather than another one', function () {
    // A visitor typing www must not land on a different company's content.
    config()->set('brand-context.hosts', ['sued.example' => 'sued']);

    $this->get('http://www.sued.example/seite')->assertJson(['marke' => 'sued']);
});

it('refuses a query override unless the site asked for one', function () {
    // On a scoped public page this is a way to read another brand's data by
    // guessing a handle, so it is off until someone turns it on.
    config()->set('brand-context.hosts', ['nord.example' => 'nord']);

    $this->get('http://nord.example/seite?brand=sued')
        ->assertJson(['marke' => 'nord', 'sichtbar' => ['n@example.com']]);

    config()->set('brand-context.allow_query_override', true);
    config()->set('brand-context.hosts', []);

    $this->get('http://nord.example/seite?brand=sued')->assertJson(['marke' => 'sued']);
});

it('says so in the log when it has to guess', function () {
    // The whole point. An unmapped request still serves something, but it is
    // never allowed to do that silently again.
    Log::spy();

    config()->set('brand-context.hosts', []);
    config()->set('brand-context.sites', []);

    $this->get('http://fremd.example/seite')->assertJson(['marke' => 'nord']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $meldung) => str_contains($meldung, 'no brand is mapped'))
        ->once();
});

it('does not touch a single-brand installation', function () {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    Log::spy();

    $this->get('http://irgendwo.example/seite')->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('hides everything rather than guessing when even the default is missing', function () {
    // The safe half of a bad situation: showing one brand's data to another
    // brand's visitors would be worse than showing none.
    Log::spy();

    config()->set('brand-context.hosts', []);
    config()->set('brand-context.default_handle', 'gibt-es-nicht');

    $antwort = $this->get('http://fremd.example/seite');

    expect($antwort->json('sichtbar'))->toBe([]);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $meldung) => str_contains($meldung, 'no brand could be resolved'))
        ->once();
});
