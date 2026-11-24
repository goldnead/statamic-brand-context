<?php

use Goldnead\BrandContext\Exceptions\NoCurrentBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\Widget;

/**
 * B1: Der Scope blendet ohne aktuelle Marke alles aus (fail closed), `creating`
 * schrieb aber in die Standardmarke. Nach `forget()` (unbekanntes Token, Queue
 * ohne Payload) landeten Zeilen unsichtbar in der falschen Marke.
 */
it('refuses to create a branded record without a current brand in multi-brand mode', function () {
    $this->enableMultiBrand();
    Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    BrandContext::forget();

    expect(fn () => Widget::create(['email' => 'x@example.com']))->toThrow(NoCurrentBrand::class);

    expect(BrandContext::withoutBrandScope(fn () => Widget::count()))->toBe(0);
});

it('still stamps the brand it is told to use, without a current brand', function () {
    $this->enableMultiBrand();
    $brand = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    BrandContext::forget();

    $w = Widget::create(['email' => 'x@example.com', 'brand_id' => $brand->id]);

    expect($w->brand_id)->toBe($brand->id);
});

it('creates inside the scope bypass, which is the explicit admin operation', function () {
    $this->enableMultiBrand();
    Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    BrandContext::forget();

    $w = BrandContext::withoutBrandScope(fn () => Widget::create(['email' => 'x@example.com']));

    expect($w->exists)->toBeTrue();
});

it('keeps single-brand installations creating without any brand set', function () {
    $w = Widget::create(['email' => 'x@example.com']);

    expect($w->brand_id)->toBe(BrandContext::defaultId());
});
