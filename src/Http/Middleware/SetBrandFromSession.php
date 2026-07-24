<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Control-Panel middleware: resolves the active brand for the request.
 * Only active in multi-brand mode; a no-op otherwise.
 *
 * Resolution order:
 *   1. ?brand=<handle> query param — an explicit switch, persisted to session.
 *   2. the session value the brand switcher wrote.
 *   3. the default brand — so the CP always has a context rather than failing
 *      closed to an empty (and confusing) listing.
 */
class SetBrandFromSession
{
    public const SESSION_KEY = 'brand-context.current';

    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled() || ! $request->hasSession()) {
            return $next($request);
        }

        $handle = $request->query('brand');

        if ($handle) {
            try {
                $manager->setCurrent($handle);
                $request->session()->put(self::SESSION_KEY, $manager->currentId());

                return $next($request);
            } catch (Throwable) {
                // Unknown handle -> fall through to session/default.
            }
        }

        $id = $request->session()->get(self::SESSION_KEY);

        if ($id) {
            try {
                $manager->setCurrent((int) $id);

                return $next($request);
            } catch (Throwable) {
                $request->session()->forget(self::SESSION_KEY);
            }
        }

        // Nothing selected yet: default to the default brand so the CP is never
        // mysteriously empty.
        $manager->setCurrent($manager->default());

        return $next($request);
    }
}
