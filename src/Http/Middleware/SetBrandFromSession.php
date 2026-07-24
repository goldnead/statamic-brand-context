<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Control-Panel middleware: sets the current brand from the session value the
 * brand switcher writes. Only active in multi-brand mode; a no-op otherwise.
 */
class SetBrandFromSession
{
    public const SESSION_KEY = 'brand-context.current';

    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('brand-context');

        if ($manager->multiBrandEnabled() && $request->hasSession()) {
            $id = $request->session()->get(self::SESSION_KEY);

            if ($id) {
                try {
                    $manager->setCurrent((int) $id);
                } catch (Throwable) {
                    // Stale/invalid brand in session -> fall back to default.
                    $request->session()->forget(self::SESSION_KEY);
                }
            }
        }

        return $next($request);
    }
}
