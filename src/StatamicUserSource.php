<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\UserSource;
use Illuminate\Support\Collection;
use Statamic\Facades\User;

/**
 * The default user source: Statamic's own user repository.
 *
 * Going through the facade rather than an Eloquent model is what makes
 * memberships work on a flat-file install. `statamic.users.repository` decides
 * whether a user is a row in `users` or a file in `users/<email>.yaml`; both
 * answer `id()` with a string, and nothing above this class ever learns which
 * of the two it is talking to.
 *
 * Returns an empty collection when Statamic is absent (a plain Laravel host, a
 * console-only context, the package's own tests) instead of fataling.
 */
class StatamicUserSource implements UserSource
{
    public function all(): Collection
    {
        if (! class_exists(User::class)) {
            return collect();
        }

        return collect(User::all()->all());
    }
}
