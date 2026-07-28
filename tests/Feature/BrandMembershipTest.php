<?php

use Goldnead\BrandContext\Contracts\UserSource;
use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\BrandContext\Tests\Fixtures\FakeIdentity;
use Goldnead\BrandContext\Tests\Fixtures\FakeUser;
use Goldnead\BrandContext\Tests\Fixtures\FakeUserSource;

function seedBrands(): array
{
    return [
        'a' => Brand::query()->where('handle', 'default')->first(),
        'b' => Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']),
        'c' => Brand::create(['handle' => 'brand-c', 'name' => 'Brand C']),
    ];
}

function useUsers(FakeUser ...$users): void
{
    app()->instance(UserSource::class, new FakeUserSource($users));
}

// ------------------------------------------------------------------ writing

it('assigns a user to a brand and is idempotent about it', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    expect(BrandMembers::attach('u-1', $brands['b']))->toBeTrue()
        ->and(BrandMembers::attach('u-1', $brands['b']))->toBeFalse()
        ->and(BrandUser::query()->where('user_id', 'u-1')->count())->toBe(1);
});

it('removes an assignment and reports whether anything was removed', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    BrandMembers::attach('u-1', $brands['b']);

    expect(BrandMembers::detach('u-1', $brands['b']))->toBeTrue()
        ->and(BrandMembers::detach('u-1', $brands['b']))->toBeFalse()
        ->and(BrandUser::query()->count())->toBe(0);
});

it('defaults to the current brand when none is named', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    BrandContextRunFor($brands['c'], fn () => BrandMembers::attach('u-1'));

    expect(BrandUser::query()->first()->brand_id)->toBe($brands['c']->id);
});

it('refuses to guess the brand when multi-brand is on and none is current', function () {
    seedBrands();
    $this->enableMultiBrand();

    // Falling back to the default brand here would answer a question about
    // brand B with brand A's memberships — in a console run or a queue worker,
    // where nobody is watching.
    expect(fn () => BrandMembers::attach('u-1'))
        ->toThrow(RuntimeException::class, 'No current brand');
});

// ------------------------------------------------------------------ reading

it('answers which brands a user belongs to', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    BrandMembers::attach('u-1', $brands['a']);
    BrandMembers::attach('u-1', $brands['c']);

    expect(BrandMembers::brandsOf('u-1')->pluck('handle')->all())
        ->toBe(['default', 'brand-c']);
});

it('answers which users belong to a brand, applying the permission filter to the caller', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    useUsers(
        new FakeUser('u-1', 'one@example.com'),
        new FakeUser('u-2', 'two@example.com'),
        new FakeUser('u-3', 'three@example.com'),
    );

    BrandMembers::attach('u-1', $brands['b']);
    BrandMembers::attach('u-2', $brands['c']);
    BrandMembers::attach('u-3', $brands['b']);

    expect(BrandMembers::usersOf($brands['b'])->map(fn ($u) => $u->id())->all())
        ->toBe(['u-1', 'u-3']);
});

it('filters a list the caller already has, with one query and the same rule', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    BrandMembers::attach('u-1', $brands['b']);
    BrandMembers::attach('u-2', $brands['c']);

    $candidates = [new FakeUser('u-1'), new FakeUser('u-2')];

    expect(BrandMembers::filter($candidates, $brands['b'])->map(fn ($u) => $u->id())->all())
        ->toBe(['u-1']);
});

it('separates the raw assignments from the resolved member list', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    useUsers(new FakeUser('u-1'), new FakeUser('u-unassigned'));

    BrandMembers::attach('u-1', $brands['b']);

    // assignedUserIdsOf() is the rows. usersOf() is the answer. The unassigned
    // user is a member of brand B without ever appearing in its assignments —
    // that difference is the whole reason for the two names.
    expect(BrandMembers::assignedUserIdsOf($brands['b'])->all())->toBe(['u-1'])
        ->and(BrandMembers::usersOf($brands['b'])->map(fn ($u) => $u->id())->all())
        ->toBe(['u-1', 'u-unassigned']);
});

// -------------------------------------------------------------- user ids

it('derives the user id from every subject shape a consumer will hold', function () {
    $brands = seedBrands();
    $this->enableMultiBrand();

    expect(BrandMembers::userId('u-1'))->toBe('u-1')
        ->and(BrandMembers::userId(42))->toBe('42')
        // A Statamic user — file driver (uuid) or eloquent driver (numeric key),
        // both answer id() with a string.
        ->and(BrandMembers::userId(new FakeUser('9c1f-uuid')))->toBe('9c1f-uuid')
        // An Identity from statamic-identity-contracts.
        ->and(BrandMembers::userId(new FakeIdentity(userId: 'u-7')))->toBe('u-7');
});

it('refuses a subject it cannot identify rather than storing a blank membership', function () {
    seedBrands();
    $this->enableMultiBrand();

    expect(fn () => BrandMembers::userId(new stdClass))->toThrow(InvalidArgumentException::class)
        // A contact-shaped Identity has no user id at all; it is not a CP user.
        ->and(fn () => BrandMembers::userId(new FakeIdentity(type: 'contact')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a user id wider than the column that has to hold it', function () {
    seedBrands();
    $this->enableMultiBrand();

    expect(fn () => BrandMembers::userId(str_repeat('x', 192)))
        ->toThrow(InvalidArgumentException::class, '191');
});

// ---------------------------------------------------------- single brand

it('treats every user as a member in single-brand mode, whatever the table says', function () {
    $brands = seedBrands();

    useUsers(new FakeUser('u-1'), new FakeUser('u-2'));

    // Recorded, but inert: single-brand has one brand and everybody is in it.
    BrandMembers::attach('u-1', $brands['a']);

    expect(BrandMembers::includes('u-2'))->toBeTrue()
        ->and(BrandMembers::usersOf()->map(fn ($u) => $u->id())->all())->toBe(['u-1', 'u-2'])
        ->and(BrandMembers::brandsOf('u-2')->pluck('handle')->all())->toBe(['default']);
});

/** Small helper so the intent reads in the test rather than the plumbing. */
function BrandContextRunFor($brand, $callback)
{
    return app('brand-context')->runFor($brand, $callback);
}
