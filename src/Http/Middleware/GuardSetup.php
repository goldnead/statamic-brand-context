<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Goldnead\BrandContext\ServiceProvider;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The setup screen for a CP detail page, shown before route-model binding.
 *
 * Every addon's `Support\Setup::guard()` protects the listing: a missing table
 * becomes a sentence instead of an HTTP 500. A detail route (`/{funnel}/edit`)
 * that takes its model by type hint never reaches that guard, because
 * `SubstituteBindings` has already run the query by the time the method begins,
 * and the query is what throws. This middleware stands in front of
 * `SubstituteBindings` (see {@see ServiceProvider}) and
 * asks the addon's own guard first, so the detail page shows the same screen
 * the listing does, and the reason still goes to the log.
 *
 * It borrows the addon's `Setup` class rather than rendering anything itself:
 * the screen, its translations and the log line stay where they already are.
 *
 * ```php
 * Route::get('/{funnel}/edit', [FunnelsController::class, 'edit'])
 *     ->middleware(GuardSetup::using(Setup::class, 'Funnels', 'funnels', 'funnel_steps'));
 * ```
 *
 * A database that is unreachable is not handled here, on purpose: that is an
 * outage, not an unfinished setup, and it should stay a 500.
 */
class GuardSetup
{
    public const ALIAS = 'brand.setup-guard';

    /**
     * The middleware string for a route: `brand.setup-guard:<setup class>,<title>,<tables…>`.
     *
     * The title is URL-encoded because Laravel splits middleware parameters on
     * commas, and a title such as "Rights, roles" would otherwise become two.
     *
     * @param  class-string  $setup  The addon's `Support\Setup`, which has `guard(string $title, string ...$tables)`.
     * @param  string  $title  The page's own heading, passed on to `guard()`. A translation key works and is the better
     *                         choice: it is translated per request, so a cached route file does not freeze the locale.
     * @param  string  ...$tables  Every table the page's query touches, bound models included.
     */
    public static function using(string $setup, string $title, string ...$tables): string
    {
        return self::ALIAS.':'.implode(',', [$setup, rawurlencode($title), ...$tables]);
    }

    public function handle(Request $request, Closure $next, string $setup, string $title, string ...$tables): Response
    {
        $guard = [$setup, 'guard'];

        if (! is_callable($guard)) {
            throw new InvalidArgumentException(sprintf(
                'brand-context: %s is used as a setup guard but has no public static guard() method.',
                $setup
            ));
        }

        // The common case, a migrated install, costs one table check and nothing else:
        // no login, no log line, the request goes on as if this middleware were not here.
        if ($this->allTablesExist($tables)) {
            return $next($request);
        }

        $run = function (Request $request) use ($next, $guard, $setup, $title, $tables): Response {
            $screen = $guard(__(rawurldecode($title)), ...$tables);

            if ($screen === null) {
                // The tables passed to using() say one is missing and the addon's own guard says the page can run. The
                // request goes on, and the Control Panel middleware that ran above run a second time, behind the binding.
                // The list belongs in step with the guard's (config-dependent lists have to be spread in at registration).
                Log::warning(sprintf(
                    'brand-context: the setup guard on [%s] found a missing table among [%s], but %s::guard() found nothing to show.',
                    $request->path(),
                    implode(', ', $tables),
                    $setup
                ));

                return $next($request);
            }

            if ($screen instanceof Responsable) {
                return $screen->toResponse($request);
            }

            return $screen instanceof Response ? $screen : response($screen);
        };

        $skipped = $this->skippedControlPanelMiddleware($request);

        if ($skipped === []) {
            return $run($request);
        }

        return (new Pipeline(app()))->send($request)->through($skipped)->then($run);
    }

    /** @param  list<string>  $tables */
    private function allTablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What this middleware's position makes the request skip, in the Control Panel.
     *
     * Ahead of `SubstituteBindings` also means ahead of everything Statamic puts
     * behind it: the login check (`Authorize`), the locale, the permissions, and
     * `HandleAuthenticatedInertiaRequests`, which shares the navigation with the
     * page. A setup screen that short-circuits without them would show a stranger
     * the table names, write a log line for every anonymous request, answer in the
     * wrong language and arrive without the sidebar. So when the screen is going
     * to be shown, the same middleware the listing runs is run first, in the
     * order Statamic defines them: what follows `SubstituteBindings` in the
     * `statamic.cp` group, then the whole `statamic.cp.authenticated` group.
     * Those that the kernel's priority list has already moved ahead of this
     * middleware (the session and authentication ones) are not run twice.
     *
     * Only the groups the route itself carries are replayed. Empty for a route
     * outside the Control Panel, and where Statamic's Control Panel is not
     * booted (a plain Laravel context, this package's own tests).
     *
     * @return list<string>
     */
    private function skippedControlPanelMiddleware(Request $request): array
    {
        $groups = app('router')->getMiddlewareGroups();

        // Only what this route itself carries: a route that is not in the Control Panel's groups (a test
        // route, a route registered some other way) skipped nothing, and replaying the groups for it would
        // run middleware that expects a Control Panel request.
        $carried = $request->route()?->gatherMiddleware() ?? [];

        $behindBindings = [];

        if (in_array('statamic.cp', $carried, true) && isset($groups['statamic.cp'])) {
            $cp = $groups['statamic.cp'];
            $at = array_search(SubstituteBindings::class, $cp, true);
            $behindBindings = $at === false ? [] : array_slice($cp, $at + 1);
        }

        $authenticated = in_array('statamic.cp.authenticated', $carried, true)
            ? ($groups['statamic.cp.authenticated'] ?? [])
            : [];

        // Aliases and nested groups in these lists are expanded the way the router would, `class:parameters` strings stay as they are.
        $resolved = app('router')->resolveMiddleware(
            [...$behindBindings, ...$authenticated],
            $request->route()?->excludedMiddleware() ?? []
        );

        $replayed = array_values(array_filter(
            $resolved,
            fn ($middleware) => ! is_string($middleware)
                || (! is_subclass_of(strtok($middleware, ':'), AuthenticatesSessions::class)
                    && ! is_subclass_of(strtok($middleware, ':'), AuthenticatesRequests::class))
        ));

        return [...$replayed, ...$this->routeAuthorization($carried, $request)];
    }

    /**
     * The route's own `can:` checks, which the kernel's priority list runs after `SubstituteBindings`
     * and so after this middleware. Without them a signed-in user who may not open the page sees the
     * setup screen, and with it the table names, that a refused request never would. They run last,
     * behind the login check, and only when the screen is going to be shown.
     *
     * Known limits, on purpose:
     *  - Only a `can:` the route names itself is picked (one entry, one alias). The Control Panel's
     *    own groups are replayed above; a custom group that carries a `can:` is not looked into.
     *  - A `can:` whose arguments name a route parameter (`can:update,post`) is left out. Before
     *    `SubstituteBindings` that parameter is the raw string from the URL, not the model, and the
     *    policy would be asked about a string. That route keeps the old behaviour (the screen).
     *  - The check runs again in the kernel when the guard has nothing to show and the request goes
     *    on; a gate with side effects sees two calls in that one case.
     *
     * @param  array<int, mixed>  $carried
     * @return list<string>
     */
    private function routeAuthorization(array $carried, Request $request): array
    {
        $router = app('router');
        $parameters = $request->route()?->parameterNames() ?? [];
        $found = [];

        foreach ($carried as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $resolved = $router->resolveMiddleware([$middleware], $request->route()?->excludedMiddleware() ?? []);

            if (count($resolved) !== 1 || ! is_string($resolved[0]) || ! is_a(strtok($resolved[0], ':'), Authorize::class, true)) {
                continue;
            }

            // `ability,argument,…`: every argument after the ability that is a route parameter is a model not yet bound.
            $arguments = array_slice(explode(',', (string) substr(strstr($resolved[0], ':') ?: '', 1)), 1);

            if (array_intersect($arguments, $parameters) !== []) {
                continue;
            }

            $found[] = $resolved[0];
        }

        return $found;
    }
}
