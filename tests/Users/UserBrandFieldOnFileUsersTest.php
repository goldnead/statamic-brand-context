<?php

use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\BrandContext\Tests\StatamicUsersTestCase;
use Goldnead\BrandContext\UserBrandField;
use Statamic\Facades\Blink;
use Statamic\Facades\User;

/**
 * Brand affiliation as a field on the user form, on the **file** users
 * repository: a user here is `users/<uuid>.yaml` and never a database row,
 * which is the case the whole design exists for. The eloquent half lives
 * beside this file.
 */
uses(StatamicUsersTestCase::class);

beforeEach(function () {
    $this->default = Brand::query()->where('handle', 'default')->first();
    $this->nord = Brand::create(['handle' => 'nord', 'name' => 'Nord']);

    // The user blueprint is blinked, and UserBlueprintFound fires only on the
    // first resolution — so anything resolved before the second brand existed
    // would be cached without the field.
    Blink::forget('user-blueprint');
});

function makeUser(?array $brands = null): object
{
    $user = User::make()->email('lea@example.com');

    if ($brands !== null) {
        $user->set(UserBrandField::HANDLE, $brands);
    }

    $user->save();

    return $user;
}

// ------------------------------------------------------------- the field

it('puts the brand field on the user form', function () {
    $blueprint = User::blueprint();

    expect($blueprint->hasField(UserBrandField::HANDLE))->toBeTrue();

    $field = $blueprint->field(UserBrandField::HANDLE);

    expect($field->type())->toBe('select')
        ->and($field->get('multiple'))->toBeTrue()
        // Every brand is offered, keyed by handle — an id would come back from
        // the form as a string and be looked up as a handle nobody has.
        ->and(array_keys($field->get('options')))->toEqualCanonicalizing(['default', 'nord']);
});

it('says at the field itself that an empty one means every brand', function () {
    // The transition rule is the single most surprising thing about
    // membership. On the old screen it had its own paragraph; if it does not
    // survive the move to a field, an operator reads an empty control as "this
    // person is in no brand" and the opposite is true.
    expect(User::blueprint()->field(UserBrandField::HANDLE)->instructions())
        ->toContain('Empty = all of them');

    app()->setLocale('de');
    Blink::forget('user-blueprint');

    expect(User::blueprint()->field(UserBrandField::HANDLE)->instructions())
        ->toContain('Leer = in allen');
});

it('leaves the users repository intact', function () {
    // The read side is registered with `User::computed()`, which resolves the
    // users repository — and on the file driver that repository is a singleton
    // built around `Stache::store('users')`. Registered one boot phase too
    // early it is built before Statamic has that store, keeps a null one for
    // the life of the process, and every `User::all()` afterwards dies inside
    // the query builder. Nothing about the field looks wrong when that
    // happens; the Control Panel just stops listing users.
    User::make()->email('mara@example.com')->save();

    expect(User::all()->count())->toBe(1);
});

it('leaves the field off an installation with a single brand', function () {
    // One brand cannot express anything: "assigned to the only brand" and
    // "assigned to nothing" both mean "included everywhere".
    $this->nord->delete();
    Blink::forget('user-blueprint');

    expect(User::blueprint()->hasField(UserBrandField::HANDLE))->toBeFalse();
});

it('leaves the field off a single-brand installation', function () {
    config()->set('brand-context.multi_brand', false);
    Blink::forget('user-blueprint');

    expect(User::blueprint()->hasField(UserBrandField::HANDLE))->toBeFalse();
});

// ------------------------------------------------------------- the saving

it('writes the submitted brands to brand_user and nothing to the user', function () {
    $user = makeUser(['nord']);

    expect(BrandMembers::assignedBrandIdsOf($user)->all())->toBe([$this->nord->id]);

    $this->assertBrandFieldNotStored($user);
});

it('assigns a user to more than one brand at once', function () {
    // The thing the old screen could never show: brand_user has always been an
    // n:m table, and the screen only ever looked at the switcher's brand, so it
    // could offer exactly one button per user.
    $user = makeUser(['default', 'nord']);

    expect(BrandMembers::assignedBrandIdsOf($user)->all())
        ->toEqualCanonicalizing([$this->default->id, $this->nord->id]);
});

it('removes an assignment when the field is cleared', function () {
    $user = makeUser(['nord']);

    $user->set(UserBrandField::HANDLE, []);
    $user->save();

    expect(BrandUser::query()->count())->toBe(0)
        ->and(BrandMembers::isUnassigned($user))->toBeTrue();
});

it('writes only the difference', function () {
    $user = makeUser(['nord']);

    $row = BrandUser::query()->first();

    $user->set(UserBrandField::HANDLE, ['nord', 'default']);
    $user->save();

    // The row that was already right is left alone. Detaching and reattaching
    // everything on every save would work and would silently churn the table.
    expect(BrandUser::query()->count())->toBe(2)
        ->and(BrandUser::query()->find($row->id))->not->toBeNull();
});

it('leaves the memberships alone on a save that never carried the field', function () {
    // Every user save in the application passes through the same listener — a
    // password reset, a sibling addon writing a preference. Reading "no brands"
    // out of those would empty the table on the next login.
    $user = makeUser(['nord']);

    $fresh = User::find($user->id());
    $fresh->set('name', 'Lea');
    $fresh->save();

    expect(BrandMembers::assignedBrandIdsOf($user)->all())->toBe([$this->nord->id]);
});

it('drops a brand nobody has instead of storing it', function () {
    // The select is not taggable, so this can only arrive from a hand-made
    // payload. A membership pointing at a brand that does not exist would be
    // invisible in the form and permanent in the table.
    $user = makeUser(['nord', 'erfunden']);

    expect(BrandUser::query()->pluck('brand_id')->all())->toBe([$this->nord->id]);
});

// ------------------------------------------------------------- the reading

it('shows the brands the user is assigned to when the form is built', function () {
    $user = makeUser(['nord']);

    expect(User::find($user->id())->computedData()->get(UserBrandField::HANDLE))
        ->toBe(['nord']);
});

it('shows an empty field for a user assigned nowhere', function () {
    // Not "every brand". An unassigned user counts everywhere, but that is a
    // rule, not a stored value — rendering it as a full field would tell the
    // operator something that is not there and turn the next save into the
    // narrowing the rule exists to postpone.
    $user = makeUser();

    expect(BrandMembers::brandsOf($user)->count())->toBe(2)
        ->and(User::find($user->id())->computedData()->get(UserBrandField::HANDLE))->toBe([]);
});
