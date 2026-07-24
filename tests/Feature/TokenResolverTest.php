<?php

use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Goldnead\BrandContext\Models\Brand;

beforeEach(function () {
    $this->resolver = app(BrandTokenResolver::class);
});

it('resolves a valid token to its brand', function () {
    $brand = Brand::factory()->withApiToken('secret-token-a')->create(['handle' => 'a']);

    expect($this->resolver->resolve('secret-token-a')?->id)->toBe($brand->id);
});

it('returns null for an unknown token (fail closed)', function () {
    Brand::factory()->withApiToken('secret-token-a')->create(['handle' => 'a']);

    expect($this->resolver->resolve('wrong-token'))->toBeNull();
});

it('returns null for an empty token', function () {
    expect($this->resolver->resolve(''))->toBeNull();
});

it('does not confuse tokens between brands', function () {
    $a = Brand::factory()->withApiToken('token-a')->create(['handle' => 'a']);
    $b = Brand::factory()->withApiToken('token-b')->create(['handle' => 'b']);

    expect($this->resolver->resolve('token-a')?->id)->toBe($a->id);
    expect($this->resolver->resolve('token-b')?->id)->toBe($b->id);
});
