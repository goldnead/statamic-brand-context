<?php

use Goldnead\BrandContext\Facades\BrandMembers;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Goldnead\BrandContext\Tests\StatamicUsersTestCase;
use Goldnead\BrandContext\UserBrandField;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Blink;
use Statamic\Facades\User;

/**
 * The same field on the **eloquent** users repository.
 *
 * The half that is easy to forget and harder to get right. Here a Statamic
 * user wraps a plain Eloquent model, so a key the blueprint knows and the
 * `users` table does not is not an untidy line in a file — it is a write to a
 * column that does not exist, which fails the save outright. And a user
 * created through this driver has no id until the row exists, which is why the
 * memberships are written on `UserSaved` rather than on `UserSaving`.
 *
 * The model is deliberately a stock `App\Models\User` shape with nothing of
 * Statamic's on it, because that is what a consuming site has.
 */
class BrandContextEloquentUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}

class BrandContextEloquentUsersTestCase extends StatamicUsersTestCase
{
    protected function usersRepository(): string
    {
        return 'eloquent';
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', BrandContextEloquentUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Exactly the columns a Statamic site running this driver has, and not
        // one more. `brand_context_brands` is absent on purpose: it is what
        // turns a leaked value into a failed save rather than a silent one.
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('super')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        // Statamic's eloquent driver reads a user's roles and groups through
        // these when it resolves permissions.
        foreach (['role_user' => 'role_id', 'group_user' => 'group_id'] as $table => $column) {
            Schema::create($table, function ($blueprint) use ($column) {
                $blueprint->increments('id');
                $blueprint->unsignedInteger('user_id');
                $blueprint->string($column);
            });
        }
    }

    protected function assertBrandFieldNotStored(object $user): void
    {
        $row = DB::table('users')->where('id', $user->id())->first();

        $this->assertNotNull($row, 'The user was not written at all.');

        $this->assertFalse(
            property_exists($row, UserBrandField::HANDLE),
            'The brand field reached the users table.'
        );

        $this->assertFalse(
            User::find($user->id())->has(UserBrandField::HANDLE),
            'The brand field came back as stored user data.'
        );
    }
}

uses(BrandContextEloquentUsersTestCase::class);

beforeEach(function () {
    $this->default = Brand::query()->where('handle', 'default')->first();
    $this->nord = Brand::create(['handle' => 'nord', 'name' => 'Nord']);

    Blink::forget('user-blueprint');
});

function makeEloquentUser(?array $brands = null): object
{
    $user = User::make()->email('lea@example.com');

    if ($brands !== null) {
        $user->set(UserBrandField::HANDLE, $brands);
    }

    $user->save();

    return $user;
}

it('puts the brand field on the user form', function () {
    expect(User::blueprint()->hasField(UserBrandField::HANDLE))->toBeTrue();
});

it('writes the submitted brands to brand_user and nothing to the users table', function () {
    // A user created through this driver has no id when UserSaving fires. If
    // the memberships were written there, this row would be keyed on an empty
    // id — or the save would throw trying to derive one.
    $user = makeEloquentUser(['nord']);

    expect($user->id())->not->toBeEmpty()
        ->and(BrandMembers::assignedBrandIdsOf($user)->all())->toBe([$this->nord->id]);

    $this->assertBrandFieldNotStored($user);
});

it('assigns a user to more than one brand at once', function () {
    $user = makeEloquentUser(['default', 'nord']);

    expect(BrandMembers::assignedBrandIdsOf($user)->all())
        ->toEqualCanonicalizing([$this->default->id, $this->nord->id]);
});

it('removes an assignment when the field is cleared', function () {
    $user = makeEloquentUser(['nord']);

    $user->set(UserBrandField::HANDLE, []);
    $user->save();

    expect(BrandUser::query()->count())->toBe(0);

    $this->assertBrandFieldNotStored($user);
});

it('leaves the memberships alone on a save that never carried the field', function () {
    $user = makeEloquentUser(['nord']);

    $fresh = User::find($user->id());
    $fresh->set('name', 'Lea');
    $fresh->save();

    expect(BrandMembers::assignedBrandIdsOf($user)->all())->toBe([$this->nord->id]);
});

it('shows the brands the user is assigned to when the form is built', function () {
    $user = makeEloquentUser(['nord']);

    expect(User::find($user->id())->computedData()->get(UserBrandField::HANDLE))
        ->toBe(['nord']);
});
