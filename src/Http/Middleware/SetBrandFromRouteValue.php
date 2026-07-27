<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-route middleware: derives the brand from a unique value in the request.
 *
 * Confirmation links, unsubscribe links and tracking pixels are opened by mail
 * clients and by browsers that have never seen the control panel. There is no
 * session, so no brand is current, and the fail-closed scope hides the very
 * record the token points at — the link 404s and the campaign statistics stay
 * at zero forever. The brand cannot come from the session here, so it comes
 * from the token, which belongs to exactly one record.
 *
 * Usage — model class, column, and the route parameter (or input field) that
 * carries the value:
 *
 *     ->middleware(SetBrandFromRouteValue::class.':'.Subscription::class.',token,token')
 *
 * Two properties make this safe rather than a hole in the isolation:
 *
 * 1. The column must carry a unique index across all brands. If it does not,
 *    the lookup throws instead of guessing (see AmbiguousBrandRecord).
 * 2. Nothing is aborted here. When no record matches, no brand is set, the
 *    scope stays closed and the controller produces exactly the response it
 *    produced before — an unknown token cannot be told apart from a token
 *    belonging to another brand, because both do nothing at all.
 *
 * Single-brand installs pass straight through.
 */
class SetBrandFromRouteValue
{
    public function handle(
        Request $request,
        Closure $next,
        string $model,
        string $column,
        string $parameter,
    ): Response {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return $next($request);
        }

        // Route parameter first; falls back to input so a posted form field
        // (a subscribe form naming its list) works the same way.
        $value = $request->route($parameter) ?? $request->input($parameter);

        $brand = $manager->brandForUnique($model, $column, $value);

        // Either outcome is set explicitly, never left to whatever happened to
        // be current. The manager is a singleton, so in a long-lived process
        // (Octane, a worker serving requests) an unknown token would otherwise
        // inherit the brand of the previous visitor — which is the one thing a
        // public route must never do.
        $brand ? $manager->setCurrent($brand) : $manager->forget();

        return $next($request);
    }
}
