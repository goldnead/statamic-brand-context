<?php

use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Tests\Fixtures\FakeUser;
use Goldnead\BrandContext\Tests\Fixtures\Widget;

/**
 * The transition rule, pinned.
 *
 * "A user with no membership anywhere counts as a member of every brand" is a
 * deliberate hole in an otherwise strict boundary. It exists so that installs
 * upgrading into this feature do not lose every name in every list overnight —
 * with an empty `brand_user` table, strict filtering would empty every assignee
 * dropdown at once and look exactly like a permissions failure.
 *
 * A rule like that decays in one direction: "counts everywhere" quietly becomes
 * "may do anything". These tests state its exact boundaries so the decay is a
 * test failure rather than a discovery.
 */
beforeEach(function () {
    $this->brands = [
        'a' => Brand::query()->where('handle', 'default')->first(),
        'b' => Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']),
        'c' => Brand::create(['handle' => 'brand-c', 'name' => 'Brand C']),
    ];

    $this->enableMultiBrand();
});

it('counts a user with no assignment at all as a member of every brand', function () {
    // This is the state of every install on the day it upgrades.
    foreach ($this->brands as $brand) {
        expect(BrandMembers::includes('never-assigned', $brand))->toBeTrue();
    }

    expect(BrandMembers::brandsOf('never-assigned')->pluck('handle')->all())
        ->toBe(['default', 'brand-b', 'brand-c']);
});

it('changes nothing for an install that has not assigned anybody', function () {
    $users = [new FakeUser('u-1'), new FakeUser('u-2'), new FakeUser('u-3')];

    // No rows in brand_user — the same three users in every brand, exactly as
    // before the upgrade.
    foreach ($this->brands as $brand) {
        expect(BrandMembers::filter($users, $brand)->map(fn ($u) => $u->id())->all())
            ->toBe(['u-1', 'u-2', 'u-3']);
    }
});

it('narrows a user the moment they get their first assignment — and only that user', function () {
    BrandMembers::attach('u-1', $this->brands['b']);

    expect(BrandMembers::includes('u-1', $this->brands['b']))->toBeTrue()
        // The first assignment is what takes them out of the other brands.
        ->and(BrandMembers::includes('u-1', $this->brands['a']))->toBeFalse()
        ->and(BrandMembers::includes('u-1', $this->brands['c']))->toBeFalse()
        // Their colleague is untouched: the rule is per user, not per install.
        ->and(BrandMembers::includes('u-2', $this->brands['a']))->toBeTrue()
        ->and(BrandMembers::includes('u-2', $this->brands['c']))->toBeTrue();
});

it('puts a user back into every brand when their last assignment is removed', function () {
    BrandMembers::attach('u-1', $this->brands['b']);
    expect(BrandMembers::includes('u-1', $this->brands['c']))->toBeFalse();

    BrandMembers::detach('u-1', $this->brands['b']);

    // There is deliberately no way to express "member of nothing". Removing the
    // last row is a return to the default state, not a lockout — locking a user
    // out is what revoking their permission is for.
    expect(BrandMembers::isUnassigned('u-1'))->toBeTrue()
        ->and(BrandMembers::includes('u-1', $this->brands['c']))->toBeTrue();
});

it('keeps a user narrowed while any assignment remains', function () {
    BrandMembers::attach('u-1', $this->brands['b']);
    BrandMembers::attach('u-1', $this->brands['c']);

    BrandMembers::detach('u-1', $this->brands['c']);

    // One row left, so the user is still "assigned" and brand A still excludes
    // them. Off-by-one here would silently re-open brand A.
    expect(BrandMembers::isUnassigned('u-1'))->toBeFalse()
        ->and(BrandMembers::includes('u-1', $this->brands['a']))->toBeFalse()
        ->and(BrandMembers::includes('u-1', $this->brands['b']))->toBeTrue();
});

it('does not let the rule leak out of membership into the record scope', function () {
    // "Counts as a member everywhere" is a statement about a person, never
    // about data. An unassigned user in brand B must still not see brand A's
    // records — the fail-closed global scope is untouched by any of this.
    $widgetA = null;

    app('brand-context')->runFor($this->brands['a'], function () use (&$widgetA) {
        $widgetA = Widget::create(['email' => 'a@example.com']);
    });

    expect(BrandMembers::includes('never-assigned', $this->brands['b']))->toBeTrue();

    app('brand-context')->runFor($this->brands['b'], function () use ($widgetA) {
        expect(Widget::find($widgetA->id))->toBeNull()
            ->and(Widget::count())->toBe(0);
    });
});
