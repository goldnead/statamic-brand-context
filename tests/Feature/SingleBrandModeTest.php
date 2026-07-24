<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\Widget;

it('has a default brand created by the migration', function () {
    $default = Brand::default();

    expect($default)->not->toBeNull();
    expect($default->is_default)->toBeTrue();
    expect($default->handle)->toBe('default');
});

it('is single-brand by default', function () {
    expect(BrandContext::multiBrandEnabled())->toBeFalse();
});

it('stamps new records with the default brand and does not isolate', function () {
    $w = Widget::create(['email' => 'x@example.com']);

    expect($w->brand_id)->toBe(BrandContext::defaultId());
    // No isolation: everything is visible, scope is a no-op.
    expect(Widget::count())->toBe(1);
});

it('behaves exactly like an unscoped model — scope adds no where clause', function () {
    $sql = Widget::query()->toSql();

    expect($sql)->not->toContain('brand_id');
});
