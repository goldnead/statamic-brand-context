<?php

namespace Goldnead\BrandContext\Facades;

use Goldnead\BrandContext\BrandMembership;
use Illuminate\Support\Facades\Facade;

/**
 * Brand membership of Control Panel users — the answer to "who belongs to this
 * brand".
 *
 * Read {@see BrandMembership} before using it: a user
 * with no membership at all counts as a member of **every** brand, so that
 * existing installs do not lose every name in every list on upgrade.
 *
 * @method static bool includes(object|string|int $user, \Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static \Illuminate\Support\Collection usersOf(\Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static \Illuminate\Support\Collection filter(iterable $users, \Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static \Illuminate\Support\Collection brandsOf(object|string|int $user)
 * @method static \Illuminate\Support\Collection assignedUserIdsOf(\Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static \Illuminate\Support\Collection assignedBrandIdsOf(object|string|int $user)
 * @method static bool isUnassigned(object|string|int $user)
 * @method static bool attach(object|string|int $user, \Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static bool detach(object|string|int $user, \Goldnead\BrandContext\Models\Brand|int|string|null $brand = null)
 * @method static string userId(object|string|int $user)
 *
 * @see BrandMembership
 */
class BrandMembers extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'brand-context.members';
    }
}
