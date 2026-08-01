<?php

use Goldnead\BrandContext\Contracts\UserSource;
use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Http\Controllers\Cp\BrandUserController;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\BrandContext\Scopes\BrandScope;
use Goldnead\BrandContext\Tests\Fixtures\FakeUser;
use Goldnead\BrandContext\Tests\Fixtures\FakeUserSource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('writes the membership to the current brand and ignores any brand the request names', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    app('brand-context')->setCurrent($this->a);

    $controller = app(BrandUserController::class);

    // The payload names brand B. The screen has no brand field, and the
    // controller never reads one — an operator of brand A cannot write into
    // brand B by editing the request.
    $request = Request::create('/cp/brands/users', 'POST', [
        'user_id' => 'u-1',
        'brand_id' => $this->b->id,
        'brand' => $this->b->handle,
    ]);
    $request->setUserResolver(fn () => new FakeUser('admin', 'admin@example.com', null, ['manage brand members']));

    $controller->store($request);

    expect(BrandUser::query()->pluck('brand_id')->all())->toBe([$this->a->id]);
});

it('will not remove another brand\'s membership from the screen of this one', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    BrandMembers::attach('u-1', $this->b);
    app('brand-context')->setCurrent($this->a);

    $request = Request::create('/cp/brands/users', 'DELETE', [
        'user_id' => 'u-1',
        'brand_id' => $this->b->id,
    ]);
    $request->setUserResolver(fn () => new FakeUser('admin', 'admin@example.com', null, ['manage brand members']));

    app(BrandUserController::class)->destroy($request);

    expect(BrandMembers::includes('u-1', $this->b))->toBeTrue()
        ->and(BrandUser::query()->count())->toBe(1);
});

it('refuses the screen to a user without the permission', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    app('brand-context')->setCurrent($this->a);

    $request = Request::create('/cp/brands/users', 'POST', ['user_id' => 'u-1']);
    $request->setUserResolver(fn () => new FakeUser('nobody', 'nobody@example.com'));

    expect(fn () => app(BrandUserController::class)->store($request))
        ->toThrow(HttpException::class);

    expect(BrandUser::query()->count())->toBe(0);
});

/**
 * Hiding the button is not authorization. Every action on this controller
 * checks the permission server-side, so every action gets the test that says
 * so — otherwise the check regresses invisibly the next time one of them is
 * touched.
 */
it('refuses a removal to a user without the permission', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    BrandMembers::attach('u-1', $this->a);
    app('brand-context')->setCurrent($this->a);

    $request = Request::create('/cp/brands/users', 'DELETE', ['user_id' => 'u-1']);
    $request->setUserResolver(fn () => new FakeUser('nobody', 'nobody@example.com'));

    expect(fn () => app(BrandUserController::class)->destroy($request))
        ->toThrow(HttpException::class);

    expect(BrandMembers::includes('u-1', $this->a))->toBeTrue()
        ->and(BrandUser::query()->count())->toBe(1);
});

it('refuses to even list the users to someone without the permission', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    app('brand-context')->setCurrent($this->a);

    $request = Request::create('/cp/brands/users', 'GET');
    $request->setUserResolver(fn () => new FakeUser('nobody', 'nobody@example.com'));

    expect(fn () => app(BrandUserController::class)->index($request, app(UserSource::class)))
        ->toThrow(HttpException::class);
});

it('says why a rejected assignment was rejected instead of failing silently', function () {
    app()->instance(UserSource::class, new FakeUserSource([new FakeUser('u-1')]));

    app('brand-context')->setCurrent($this->a);

    // The user was deleted between rendering the screen and clicking the
    // button. Writing the row anyway would leave a membership pointing at
    // nobody; refusing without a message reads as a dead button.
    $request = Request::create('/cp/brands/users', 'POST', ['user_id' => 'u-gone']);
    $request->setUserResolver(fn () => new FakeUser('admin', 'admin@example.com', null, ['manage brand members']));

    $response = app(BrandUserController::class)->store($request);

    expect(BrandUser::query()->count())->toBe(0)
        ->and($response->getSession()->get('errors')->getBag('default')->first('user_id'))
        ->not->toBeEmpty();
});
