<?php

use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\BrandContext\Scopes\BrandScope;

/**
 * Membership is a security surface: it decides who a brand may hand work to,
 * notify, or ask for an approval. The isolation therefore has to be asserted,
 * not inspected in a screenshot — it lives exactly where there is no interface
 * to photograph.
 *
 * Unlike every other table in this package, `brand_user` is NOT under the
 * global scope (see BrandUser for why). Its boundary is the explicit brand id
 * in every query, so these tests check the queries rather than the scope.
 */
beforeEach(function () {
    $this->a = Brand::query()->where('handle', 'default')->first();
    $this->b = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->enableMultiBrand();
});

it('does not make a membership of one brand visible in another', function () {
    BrandMembers::attach('u-1', $this->a);

    expect(BrandMembers::assignedUserIdsOf($this->a)->all())->toBe(['u-1'])
        ->and(BrandMembers::assignedUserIdsOf($this->b)->all())->toBe([]);
});

it('does not make a membership of one brand effective in another', function () {
    BrandMembers::attach('u-1', $this->a);

    // Visibility and effect are different failures. A member list that is
    // merely rendered wrong is a bug; includes() returning true is an
    // authorisation decision made on the wrong brand.
    expect(BrandMembers::includes('u-1', $this->a))->toBeTrue()
        ->and(BrandMembers::includes('u-1', $this->b))->toBeFalse();
});

it('keeps the two brands apart when the same user belongs to both', function () {
    BrandMembers::attach('u-1', $this->a);
    BrandMembers::attach('u-1', $this->b);

    BrandMembers::detach('u-1', $this->a);

    // Removing the user from one brand must not touch the other row. A
    // delete()->where('user_id') without the brand would pass every other test
    // in this file and fail here.
    expect(BrandMembers::includes('u-1', $this->a))->toBeFalse()
        ->and(BrandMembers::includes('u-1', $this->b))->toBeTrue()
        ->and(BrandUser::query()->count())->toBe(1);
});

it('leaves brand_user out of the global scope on purpose', function () {
    // Pinned so nobody "completes" the pattern later. Under the scope, a
    // console run (no current brand, fail closed) would read zero membership
    // rows, and the transition rule would turn that into "member of every
    // brand" for everyone — the boundary would invert exactly where no session
    // exists to notice.
    expect(array_keys((new BrandUser)->getGlobalScopes()))->not->toContain(BrandScope::class);

    BrandMembers::attach('u-1', $this->a);

    app('brand-context')->forget();

    expect(app('brand-context')->hasCurrent())->toBeFalse()
        ->and(BrandUser::query()->count())->toBe(1)
        ->and(BrandMembers::includes('u-1', $this->a))->toBeTrue()
        ->and(BrandMembers::includes('u-1', $this->b))->toBeFalse();
});
