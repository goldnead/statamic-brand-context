<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\UserSource;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Which Control Panel users belong to which brand.
 *
 * The rest of this package isolates Eloquent models through a global scope. A
 * Statamic user is not one of those — it may not be a database row at all —
 * so "the users of this brand" cannot be expressed by scoping and needs its
 * own, explicit answer. This class is that answer, and it is deliberately the
 * only one: sibling addons ask here instead of each inventing a membership of
 * their own.
 *
 * ## The one rule that will surprise you
 *
 * **A user with no membership at all counts as a member of every brand.**
 *
 * Not a convenience — a migration path. Every install that upgrades into this
 * feature starts with an empty `brand_user` table. Strict filtering would empty
 * every assignee dropdown, every team notification and every approval list on
 * the day of the upgrade, and it would look exactly like a permissions bug: the
 * names are gone, nobody knows why, and the fix is invisible. So nothing
 * changes until somebody deliberately assigns a user. The first assignment for
 * a user is what narrows that user down — and it narrows them everywhere at
 * once, because from then on they are "assigned", and every brand not on their
 * list stops including them.
 *
 * Ask it through {@see includes()} or {@see usersOf()} and the rule is applied
 * for you. {@see assignedUserIdsOf()} returns the raw rows and does *not* apply
 * it; it is named that way so it cannot be mistaken for the member list.
 *
 * ## What a consumer is expected to do
 *
 * Membership is brand affiliation, never authorisation. The permission check
 * stays where it belongs, in the consuming addon:
 *
 * ```php
 * use Goldnead\BrandContext\Facades\BrandMembers;
 *
 * $assignees = BrandMembers::usersOf()                     // current brand
 *     ->filter(fn ($user) => $user->can('view leadhub'))   // your permission
 *     ->map(fn ($user) => ['value' => (string) $user->id(), 'label' => $user->email()]);
 * ```
 *
 * ## Single-brand installs
 *
 * Everything belongs to the one brand: {@see includes()} is always true and
 * {@see usersOf()} returns every user. Memberships may still be recorded — they
 * simply have no effect until multi-brand is switched on.
 */
class BrandMembership
{
    public function __construct(protected BrandManager $manager) {}

    // ---------------------------------------------------------------- read

    /**
     * Does this user belong to this brand?
     *
     * The transition rule is applied here: a user with no membership rows at
     * all belongs everywhere. Always true in single-brand mode.
     *
     * @param  object|string|int  $user  A Statamic user, an Authenticatable, an
     *                                   Identity, an Eloquent model, or a raw id.
     * @param  Brand|int|string|null  $brand  Defaults to the current brand.
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function includes(object|string|int $user, Brand|int|string|null $brand = null): bool
    {
        if (! $this->manager->multiBrandEnabled()) {
            return true;
        }

        $userId = $this->userId($user);
        $brandId = $this->brandId($brand);

        if (BrandUser::query()->where('user_id', $userId)->where('brand_id', $brandId)->exists()) {
            return true;
        }

        return $this->isUnassigned($userId);
    }

    /**
     * The users of a brand: every Control Panel user that {@see includes()}
     * accepts, in the order the user source returns them.
     *
     * This is the method to build a picker from. It returns user objects, not
     * ids, so the caller can read a name and apply its own permission filter
     * without a second lookup.
     *
     * @return Collection<int, object>
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function usersOf(Brand|int|string|null $brand = null): Collection
    {
        return $this->filter(app(UserSource::class)->all(), $brand);
    }

    /**
     * The members of a brand among the users you already have.
     *
     * Same rule as {@see usersOf()}, one query instead of one per user — for
     * callers that have already narrowed the list down (by permission, by
     * role) and only want the brand filter applied to it.
     *
     * @param  iterable<int, object|string|int>  $users
     * @return Collection<int, object|string|int>
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function filter(iterable $users, Brand|int|string|null $brand = null): Collection
    {
        $users = collect($users);

        if (! $this->manager->multiBrandEnabled()) {
            return $users->values();
        }

        $brandId = $this->brandId($brand);

        // One read for both halves of the rule. The table holds one row per
        // (brand, user), so it stays small enough to answer this in memory —
        // and doing it in one place keeps the rule from drifting apart from
        // includes().
        $rows = BrandUser::query()->get(['brand_id', 'user_id']);

        $assignedAnywhere = $rows->pluck('user_id')->map(strval(...))->unique();
        $assignedHere = $rows->where('brand_id', $brandId)->pluck('user_id')->map(strval(...))->unique();

        return $users
            ->filter(function ($user) use ($assignedHere, $assignedAnywhere) {
                $id = $this->userId($user);

                return $assignedHere->containsStrict($id) || ! $assignedAnywhere->containsStrict($id);
            })
            ->values();
    }

    /**
     * The brands a user belongs to.
     *
     * The mirror image of {@see usersOf()}, transition rule included: an
     * unassigned user belongs to every brand, and in single-brand mode the
     * answer is always the one default brand.
     *
     * @return Collection<int, Brand>
     */
    public function brandsOf(object|string|int $user): Collection
    {
        if (! $this->manager->multiBrandEnabled()) {
            return collect([$this->manager->default()]);
        }

        $ids = $this->assignedBrandIdsOf($user);

        if ($ids->isEmpty()) {
            return Brand::query()->orderBy('id')->get();
        }

        return Brand::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    // ------------------------------------------------------------ raw reads

    /**
     * The user ids explicitly assigned to a brand.
     *
     * **This is not the member list.** It ignores the transition rule, so users
     * that {@see includes()} accepts because they are assigned nowhere are
     * missing from it. Use it to render or audit the assignments themselves —
     * a management screen, a report — never to decide who may be offered,
     * notified or assigned.
     *
     * @return Collection<int, string>
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function assignedUserIdsOf(Brand|int|string|null $brand = null): Collection
    {
        return BrandUser::query()
            ->where('brand_id', $this->brandId($brand))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(strval(...))
            ->values();
    }

    /**
     * The brand ids explicitly assigned to a user. Empty means "assigned
     * nowhere", which the transition rule reads as "everywhere" — see
     * {@see brandsOf()} for the resolved answer.
     *
     * @return Collection<int, int>
     */
    public function assignedBrandIdsOf(object|string|int $user): Collection
    {
        return BrandUser::query()
            ->where('user_id', $this->userId($user))
            ->orderBy('brand_id')
            ->pluck('brand_id')
            ->map(intval(...))
            ->values();
    }

    /** Has this user no membership anywhere, and therefore counts everywhere? */
    public function isUnassigned(object|string|int $user): bool
    {
        return ! BrandUser::query()->where('user_id', $this->userId($user))->exists();
    }

    // --------------------------------------------------------------- write

    /**
     * Assign a user to a brand. Idempotent; returns true when a row was added.
     *
     * The first assignment for a user is the moment they stop being a member
     * everywhere. That is intended, and it is the reason a management screen
     * should say so.
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function attach(object|string|int $user, Brand|int|string|null $brand = null): bool
    {
        $userId = $this->userId($user);
        $brandId = $this->brandId($brand);

        if (BrandUser::query()->where('brand_id', $brandId)->where('user_id', $userId)->exists()) {
            return false;
        }

        BrandUser::query()->create(['brand_id' => $brandId, 'user_id' => $userId]);

        return true;
    }

    /**
     * Remove a user from a brand. Returns true when a row was deleted.
     *
     * Removing the *last* assignment a user has puts them back into the
     * unassigned state, and therefore back into every brand. There is no way to
     * express "member of nothing" — that is what revoking the permission is for.
     *
     * @throws RuntimeException when $brand is null and no brand is current
     */
    public function detach(object|string|int $user, Brand|int|string|null $brand = null): bool
    {
        return BrandUser::query()
            ->where('brand_id', $this->brandId($brand))
            ->where('user_id', $this->userId($user))
            ->delete() > 0;
    }

    // ------------------------------------------------------------- plumbing

    /**
     * The id this package stores for a subject.
     *
     * Accepts what the surrounding family actually passes around: a Statamic
     * user (`id()`, either driver), an `Identity` from
     * goldnead/statamic-identity-contracts (`->userId`), an `Authenticatable`,
     * an Eloquent model, or an id that has already been extracted.
     */
    public function userId(object|string|int $user): string
    {
        $id = match (true) {
            is_string($user), is_int($user) => $user,
            // Identity (statamic-identity-contracts) and anything else that
            // already carries a stringified user key. Checked first, because an
            // Identity is a value object with no id() and no key. property_exists
            // rather than isset(), so a magic __isset() on a user object cannot
            // answer for a property it does not have.
            property_exists($user, 'userId') => $user->userId,
            method_exists($user, 'id') => $user->id(),
            $user instanceof Authenticatable => $user->getAuthIdentifier(),
            $user instanceof Model => $user->getKey(),
            default => null,
        };

        if ($id === null || $id === '') {
            throw new InvalidArgumentException(
                'Cannot derive a user id from ['.(is_object($user) ? $user::class : gettype($user)).'].'
            );
        }

        $id = (string) $id;

        if (mb_strlen($id) > 191) {
            throw new InvalidArgumentException('User id exceeds the 191 characters brand_user.user_id stores.');
        }

        return $id;
    }

    /**
     * The brand id a membership operation applies to.
     *
     * Null means the current brand — but only when there *is* one. With
     * multi-brand on and no brand resolved (a console run, a queue worker),
     * falling back to the default brand would answer a question about brand B
     * with brand A's memberships, so this refuses instead. Console callers pass
     * the brand explicitly, or wrap the work in `BrandContext::runFor()` /
     * the `RunsForEachBrand` trait.
     */
    protected function brandId(Brand|int|string|null $brand): int
    {
        if ($brand instanceof Brand) {
            return $brand->id;
        }

        // Resolved against the brands table directly, not through the manager:
        // in single-brand mode `current()` answers "the default brand" whatever
        // has been set, which would silently redirect an explicitly named brand
        // to the default one.
        if (is_int($brand)) {
            $resolved = Brand::query()->find($brand);
        } elseif ($brand !== null) {
            $resolved = Brand::query()->where('handle', $brand)->first();
        } else {
            $resolved = null;
        }

        if ($brand !== null) {
            if (! $resolved) {
                throw new RuntimeException("Unknown brand [{$brand}].");
            }

            return $resolved->id;
        }

        if (! $this->manager->hasCurrent()) {
            throw new RuntimeException(
                'No current brand. Brand membership must name the brand it means — pass one explicitly, '.
                'or run inside BrandContext::runFor() / the RunsForEachBrand trait.'
            );
        }

        return $this->manager->currentId();
    }
}
