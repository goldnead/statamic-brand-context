<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\Widget;

beforeEach(function () {
    $this->enableMultiBrand();

    $this->brandA = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::factory()->create(['handle' => 'brand-b', 'name' => 'Brand B']);
});

it('stamps new records with the current brand', function () {
    BrandContext::setCurrent($this->brandA);
    $w = Widget::create(['email' => 'x@example.com']);

    expect($w->brand_id)->toBe($this->brandA->id);
});

it('hides another brand data from the current brand — the security boundary', function () {
    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'a@example.com']));
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b@example.com']));

    BrandContext::setCurrent($this->brandA);
    expect(Widget::count())->toBe(1);
    expect(Widget::first()->email)->toBe('a@example.com');

    BrandContext::setCurrent($this->brandB);
    expect(Widget::count())->toBe(1);
    expect(Widget::first()->email)->toBe('b@example.com');
});

it('cannot read a specific other-brand record by id', function () {
    $bWidget = BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b@example.com']));

    BrandContext::setCurrent($this->brandA);

    expect(Widget::find($bWidget->id))->toBeNull();
});

it('fails closed: with no current brand and fail_mode=closed, no rows are returned', function () {
    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'a@example.com']));

    BrandContext::forget();
    expect(BrandContext::hasCurrent())->toBeFalse();
    expect(Widget::count())->toBe(0);
});

it('allows the same email across brands but enforces uniqueness within a brand', function () {
    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'same@example.com']));

    // Same email, different brand -> allowed (unique is (brand_id, email)).
    $ok = BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'same@example.com']));
    expect($ok->exists)->toBeTrue();

    // Same email, same brand -> rejected.
    BrandContext::runFor($this->brandA, function () {
        Widget::create(['email' => 'same@example.com']);
    });
})->throws(Illuminate\Database\QueryException::class);

it('withoutBrandScope sees all brands (explicit admin op)', function () {
    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'a@example.com']));
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b@example.com']));

    BrandContext::setCurrent($this->brandA);

    $all = BrandContext::withoutBrandScope(fn () => Widget::count());
    expect($all)->toBe(2);
});

it('forBrand query scope targets a specific brand regardless of current', function () {
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b@example.com']));

    BrandContext::setCurrent($this->brandA);

    expect(Widget::forBrand($this->brandB)->count())->toBe(1);
});
