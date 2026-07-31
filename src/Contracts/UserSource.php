<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\BrandMembership;
use Goldnead\BrandContext\StatamicUserSource;
use Illuminate\Support\Collection;

/**
 * Where the list of Control Panel users comes from.
 *
 * Bound to {@see StatamicUserSource}, which asks
 * `Statamic\Facades\User` and therefore works with the file users repository
 * and the eloquent one alike — that repository is the abstraction, so this
 * package never needs to know which driver an install uses.
 *
 * It is a contract rather than a direct call for two reasons: a host
 * application with its own notion of "staff" can substitute one, and the
 * membership logic stays testable without booting the whole CMS.
 */
interface UserSource
{
    /**
     * Every user the Control Panel knows, in no particular order.
     *
     * Elements are Statamic user objects (`id()`, `email()`, `name()`); a
     * substitute implementation may return anything from which
     * {@see BrandMembership::userId()} can read an id.
     *
     * @return Collection<int, object>
     */
    public function all(): Collection;
}
