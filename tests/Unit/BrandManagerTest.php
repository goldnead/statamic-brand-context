<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;

it('respects the license gate when set', function () {
    // Multi-brand flag on, but license denies -> effectively single-brand.
    $this->enableMultiBrand(fn () => false);
    expect(BrandContext::multiBrandEnabled())->toBeFalse();

    // License allows -> multi-brand active.
    $this->enableMultiBrand(fn () => true);
    expect(BrandContext::multiBrandEnabled())->toBeTrue();
});

it('runFor restores the previous current brand', function () {
    $this->enableMultiBrand();
    $a = Brand::factory()->create(['handle' => 'a']);
    $b = Brand::factory()->create(['handle' => 'b']);

    BrandContext::setCurrent($a);

    $inside = BrandContext::runFor($b, fn () => BrandContext::currentId());

    expect($inside)->toBe($b->id);
    expect(BrandContext::currentId())->toBe($a->id);
});

it('current() falls back to default in multi-brand when nothing set', function () {
    $this->enableMultiBrand();

    expect(BrandContext::current()->is_default)->toBeTrue();
    expect(BrandContext::hasCurrent())->toBeFalse();
});

it('resolves brands by handle and id', function () {
    $this->enableMultiBrand();
    $a = Brand::factory()->create(['handle' => 'acme']);

    BrandContext::setCurrent('acme');
    expect(BrandContext::currentId())->toBe($a->id);

    BrandContext::setCurrent($a->id);
    expect(BrandContext::currentId())->toBe($a->id);
});

it('throws on an unknown brand handle', function () {
    $this->enableMultiBrand();

    BrandContext::setCurrent('does-not-exist');
})->throws(RuntimeException::class);
