<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Models\Brand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Statamic\Auth\Eloquent\User as EloquentUser;
use Statamic\Fields\Blueprint;
use WeakMap;

/**
 * Brand affiliation as a field on Statamic's own user form.
 *
 * It used to be a screen of its own with a nav item, and nobody could tell what
 * it was for: it acted on whichever brand the switcher happened to hold, so it
 * showed one button per user and looked like a permission toggle. It is not a
 * permission — it decides in which brands a person is offered as an assignee —
 * and it was always an n:m relation. Both facts are legible in one multi-select
 * on the user itself, and neither was legible on a screen that could only ever
 * name one brand.
 *
 * ## Why the value is not stored in the blueprint
 *
 * A Statamic user is not necessarily a database row. On the file driver it is
 * `users/<email>.yaml` with a uuid for an id, and there is nothing to put a
 * foreign key on — which is why `brand_user` has none, and why the affiliation
 * has to keep living there rather than in the user's own data.
 *
 * So the field is grafted on at three points and its value never reaches the
 * user's storage:
 *
 * - {@see addTo()} on `UserBlueprintFound` puts the field in the form.
 * - The read side is a computed callback (`Statamic\Facades\User::computed`),
 *   which `ExtractsFromUserFields` merges over the user's own data. Nothing is
 *   read from storage because nothing was written there.
 * - {@see takeFrom()} on `UserSaving` lifts the submitted value back off the
 *   user *before* it is written, and {@see sync()} on `UserSaved` turns it into
 *   `brand_user` rows.
 *
 * The split across two events is not decoration. On the eloquent driver a new
 * user has no id until the row exists, so a sync done in `UserSaving` would
 * have nothing to key the memberships on — while the stripping has to happen
 * there, because by `UserSaved` the value would already be on disk.
 *
 * ## The rule that stays
 *
 * A user assigned to no brand at all counts as a member of every brand
 * ({@see BrandMembership}). That is the migration path — every install starts
 * with an empty table — and it is why the field's own instructions end with
 * "empty = all of them". An operator who reads the field must not have to read
 * the CHANGELOG to understand an empty one.
 */
class UserBrandField
{
    /**
     * Namespaced on purpose. A plain `brands` would be the obvious handle and
     * the obvious collision: `ensureField()` never overwrites an existing
     * field, so a site that already has one would keep its own — and then
     * {@see takeFrom()} would quietly strip that field's value on every save.
     */
    public const HANDLE = 'brand_context_brands';

    /**
     * Values lifted off a user in `UserSaving`, waiting for `UserSaved`.
     *
     * A WeakMap rather than an array keyed by object id: an aborted save (a
     * later listener returning false) never reaches `UserSaved`, and an entry
     * left behind under a recycled `spl_object_id` would be applied to an
     * unrelated user later in the same request.
     *
     * The values are whatever the form submitted, unvalidated — the filtering
     * against the brands that exist happens in {@see sync()}.
     *
     * @var WeakMap<object, array<array-key, mixed>>
     */
    protected WeakMap $pending;

    protected ?bool $installed = null;

    public function __construct(
        protected BrandManager $manager,
        protected BrandMembership $members,
    ) {
        $this->pending = new WeakMap;
    }

    /**
     * Is there anything for this field to say?
     *
     * Multi-brand only, and only from the second brand on. With exactly one
     * brand the control cannot express anything: "assigned to the only brand"
     * and "assigned to nothing" both mean "included everywhere" —
     * {@see BrandMembership::includes()} and `filter()` return the same answer
     * either way. A multi-select with one option that changes no behaviour is
     * noise on the form of every user. It appears by itself once a second
     * brand exists.
     */
    public function applies(): bool
    {
        return $this->manager->multiBrandEnabled() && $this->brands()->count() > 1;
    }

    // ------------------------------------------------------------- the field

    public function addTo(Blueprint $blueprint): void
    {
        if (! $this->applies()) {
            return;
        }

        $blueprint->ensureField(self::HANDLE, [
            'type' => 'select',
            'multiple' => true,
            'clearable' => true,
            'display' => __('brand-context::messages.user_brands_display'),
            'instructions' => __('brand-context::messages.user_brands_instructions'),
            // Handles, not ids. The submitted value comes back as strings, and
            // BrandMembership resolves a string as a handle and an int as an
            // id — so ids would arrive as "3" and be looked up as a handle
            // nobody has. Handles also survive being read by a human.
            'options' => $this->brands()->pluck('name', 'handle')->all(),
            // Never a column on the users listing: the value is not stored, so
            // a column would run the computed callback — two queries — for
            // every row on every page of the listing, and could not be sorted
            // or filtered on at all.
            'listable' => false,
            'filterable' => false,
            'sortable' => false,
        ]);
    }

    /**
     * The value the form shows: the brands this user is *explicitly* assigned
     * to, and nothing else.
     *
     * Deliberately `assignedBrandIdsOf()` rather than `brandsOf()`. The latter
     * applies the transition rule and would answer "every brand" for an
     * unassigned user — which would render the field full, tell the operator
     * something that is not stored, and turn the first save into the very
     * narrowing the rule exists to postpone.
     *
     * @return array<int, string>
     */
    public function currentValue(object $user): array
    {
        if (! $this->applies() || ! $this->hasId($user)) {
            return [];
        }

        $ids = $this->members->assignedBrandIdsOf($user)->all();

        return $this->brands()->whereIn('id', $ids)->pluck('handle')->all();
    }

    // ------------------------------------------------------------ the saving

    /**
     * Take the submitted value off the user before anything writes it down.
     *
     * `has()` is a safe question here: the field is `multiple`, and
     * `HasSelectOptions::process()` wraps whatever arrives — null included —
     * into an array. So the key is present as an array exactly when the user
     * form was submitted, and absent on every other save (a password reset, a
     * login timestamp, a sibling addon writing to the user), which is what
     * keeps those saves from reading "no brands" and wiping the memberships.
     */
    public function takeFrom(object $user): void
    {
        if (! $this->applies() || ! $user->has(self::HANDLE)) {
            return;
        }

        $this->pending[$user] = (array) $user->get(self::HANDLE);

        $this->forget($user);
    }

    /**
     * Turn the value taken in {@see takeFrom()} into `brand_user` rows.
     *
     * Runs on `UserSaved`, where a freshly created eloquent user finally has
     * an id. Only the difference is written, so a save that changes nothing
     * touches no row — and a handle nobody has is dropped rather than stored,
     * because a membership pointing at a brand that does not exist would be
     * invisible in the form and permanent in the table.
     */
    public function sync(object $user): void
    {
        if (! isset($this->pending[$user])) {
            return;
        }

        $submitted = collect($this->pending[$user])->map(strval(...));

        unset($this->pending[$user]);

        if (! $this->hasId($user)) {
            return;
        }

        $wanted = $this->brands()
            ->filter(fn (Brand $brand) => $submitted->containsStrict($brand->handle))
            ->pluck('id');

        $current = $this->members->assignedBrandIdsOf($user);

        foreach ($wanted->diff($current) as $id) {
            $this->members->attach($user, (int) $id);
        }

        foreach ($current->diff($wanted) as $id) {
            $this->members->detach($user, (int) $id);
        }
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Make the key go away, whichever driver is underneath.
     *
     * The two drivers need opposite calls, and each one's wrong call is a real
     * defect rather than an untidiness:
     *
     * - File: `remove()` forgets the key. `set(null)` would leave it in
     *   `$data`, and `fileData()` does not strip nulls, so the yaml would grow
     *   a `brand_context_brands: null` line — the stored value this whole
     *   class exists to avoid.
     * - Eloquent: `set(null)` unsets the model attribute. `remove()` assigns
     *   null to it, which leaves the attribute dirty and makes the next
     *   `save()` write a column the `users` table does not have.
     */
    protected function forget(object $user): void
    {
        $user instanceof EloquentUser
            ? $user->set(self::HANDLE, null)
            : $user->remove(self::HANDLE);
    }

    /**
     * A user that cannot say who it is cannot have memberships read or written
     * for it. `BrandMembership::userId()` would throw, and it would throw from
     * inside a computed callback — taking the whole user form down over a case
     * that only means "there is nothing to show yet".
     */
    protected function hasId(object $user): bool
    {
        return method_exists($user, 'id') && (string) $user->id() !== '';
    }

    /**
     * @return Collection<int, Brand>
     */
    protected function brands(): Collection
    {
        // Not memoised: this object is a singleton, and a brand list frozen at
        // first use would outlive a brand created later in the same process —
        // in a console run, in a test, in an install wizard. Only the schema
        // question is cached, because that one genuinely cannot change under
        // a running request.
        $this->installed ??= Schema::hasTable('brands');

        if (! $this->installed) {
            return collect();
        }

        return Brand::query()->orderBy('name')->get();
    }
}
