<?php

use Goldnead\BrandContext\Exceptions\AmbiguousBrandRecord;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\Widget;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->enableMultiBrand();

    $this->brandA = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::factory()->create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->aWidget = BrandContext::runFor($this->brandA, fn () => Widget::create([
        'email' => 'a@example.com', 'token' => 'token-a',
    ]));
    $this->bWidget = BrandContext::runFor($this->brandB, fn () => Widget::create([
        'email' => 'b@example.com', 'token' => 'token-b',
    ]));

    BrandContext::forget();
});

/** The route a mail client or a stranger's browser hits: no session at all. */
function publicRoute(): void
{
    Route::get('/public/{token}', function (string $token) {
        $widget = Widget::query()->where('token', $token)->first();

        return response()->json([
            'found' => (bool) $widget,
            'email' => $widget?->email,
            'brand' => BrandContext::hasCurrent() ? BrandContext::current()->handle : null,
            'visible' => Widget::count(),
        ], $widget ? 200 : 404);
    })->middleware(['web', SetBrandFromRouteValue::class.':'.Widget::class.',token,token']);
}

it('finds the brand that owns the record, whatever is current', function () {
    expect(BrandContext::brandForUnique(Widget::class, 'token', 'token-b')->handle)->toBe('brand-b');

    BrandContext::setCurrent($this->brandA);
    expect(BrandContext::brandForUnique(Widget::class, 'token', 'token-b')->handle)->toBe('brand-b');
});

it('returns null for a value nobody holds', function () {
    expect(BrandContext::brandForUnique(Widget::class, 'token', 'nope'))->toBeNull();
    expect(BrandContext::brandForUnique(Widget::class, 'token', null))->toBeNull();
    expect(BrandContext::brandForUnique(Widget::class, 'token', ''))->toBeNull();
});

it('refuses to guess when a column is only unique per brand', function () {
    // `handle` carries no global unique index, so two brands can hold the same
    // value — exactly the situation in which picking one would serve brand A's
    // visitor a record of brand B.
    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'a2@example.com', 'handle' => 'shared']));
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b2@example.com', 'handle' => 'shared']));
    BrandContext::forget();

    expect(fn () => BrandContext::brandForUnique(Widget::class, 'handle', 'shared'))
        ->toThrow(AmbiguousBrandRecord::class);
});

it('lets a sessionless request reach the record its token points at', function () {
    publicRoute();

    $this->get('/public/token-b')
        ->assertOk()
        ->assertJson(['found' => true, 'email' => 'b@example.com', 'brand' => 'brand-b']);
});

it('shows that request only its own brand — the security boundary', function () {
    publicRoute();

    // Two brands hold one record each. A request carrying brand B's token must
    // see exactly one, and it must be B's.
    $this->get('/public/token-b')->assertJson(['visible' => 1, 'email' => 'b@example.com']);
    $this->get('/public/token-a')->assertJson(['visible' => 1, 'email' => 'a@example.com']);
});

it('leaves an unknown token exactly as it was before: nothing found, nothing set', function () {
    publicRoute();

    $this->get('/public/whatever')
        ->assertNotFound()
        ->assertJson(['found' => false, 'brand' => null, 'visible' => 0]);
});

it('does not leak the brand across two requests', function () {
    publicRoute();

    $this->get('/public/token-b')->assertJson(['brand' => 'brand-b']);
    $this->get('/public/whatever')->assertJson(['brand' => null, 'visible' => 0]);
});

it('passes straight through in a single-brand install', function () {
    config()->set('brand-context.multi_brand', false);
    app('brand-context')->forget();

    publicRoute();

    // No brand is set and none is needed: the scope is inert, so the record is
    // reachable exactly as it was before the package existed.
    $this->get('/public/token-a')->assertOk()->assertJson(['found' => true]);
});
