<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API middleware: resolves the current brand from the Bearer token.
 *
 * Single-brand mode: pass-through (the default brand is always current).
 * Multi-brand mode: fail-closed — a missing or unknown token aborts with 401
 * so no request ever runs without an explicit brand context.
 */
class ResolveBrandFromToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return $next($request);
        }

        $token = $request->bearerToken();
        $brand = $token ? app(BrandTokenResolver::class)->resolve($token) : null;

        if (! $brand) {
            abort(401, 'Invalid or missing brand token.');
        }

        $manager->setCurrent($brand);

        return $next($request);
    }
}
