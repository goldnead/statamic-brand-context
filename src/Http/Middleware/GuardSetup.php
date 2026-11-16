<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Goldnead\BrandContext\ServiceProvider;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Statamic\Http\Middleware\CP\AuthGuard;
use Statamic\Http\Middleware\CP\Authorize;
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
     * @param  string  $title  The page's own heading, passed on to `guard()`.
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

        $run = function (Request $request) use ($next, $guard, $title, $tables): Response {
            $screen = $guard(rawurldecode($title), ...$tables);

            if ($screen === null) {
                return $next($request);
            }

            return $screen instanceof Responsable ? $screen->toResponse($request) : $screen;
        };

        return $this->behindControlPanelLogin($request, $run);
    }

    /**
     * Ahead of `SubstituteBindings` also means ahead of the login check, which
     * Statamic's `Authorize` middleware makes after it. Without this, a
     * stranger would be shown the setup screen (and the table names on it) for
     * a page behind the login, and the guard would write a log line on every
     * anonymous request. Where Statamic's CP is not booted (a plain Laravel
     * context, the tests of this package) there is nothing to ask.
     */
    private function behindControlPanelLogin(Request $request, Closure $run): Response
    {
        $isControlPanel = class_exists(Authorize::class)
            && array_key_exists('statamic.cp', app('router')->getMiddlewareGroups());

        if (! $isControlPanel) {
            return $run($request);
        }

        return app(AuthGuard::class)->handle(
            $request,
            fn (Request $request) => app(Authorize::class)->handle($request, $run)
        );
    }
}
