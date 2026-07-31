<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Http\Request;
use Statamic\Statamic;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Control-Panel middleware: resolves the active brand for the request and hands
 * the brand list + current brand to the CP JavaScript (for the switcher).
 * Only active in multi-brand mode; a no-op otherwise.
 *
 * Resolution order:
 *   1. ?brand=<handle> query param — an explicit switch, persisted to session.
 *   2. the session value the switcher wrote.
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
            } catch (Throwable) {
                // Unknown handle -> fall through to session/default.
                $this->resolveFromSessionOrDefault($manager, $request);
            }
        } else {
            $this->resolveFromSessionOrDefault($manager, $request);
        }

        $this->provideToScript($manager);

        return $next($request);
    }

    protected function resolveFromSessionOrDefault($manager, Request $request): void
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if ($id) {
            try {
                $manager->setCurrent((int) $id);

                return;
            } catch (Throwable) {
                $request->session()->forget(self::SESSION_KEY);
            }
        }

        // Nothing selected yet: default to the default brand so the CP is never
        // mysteriously empty.
        $manager->setCurrent($manager->default());
    }

    /**
     * Hand the brand list + current brand to the CP JS for the switcher.
     * Guarded so the middleware stays usable outside a Statamic context.
     */
    protected function provideToScript($manager): void
    {
        if (! class_exists(Statamic::class)) {
            return;
        }

        try {
            $brands = Brand::query()
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'handle', 'name'])
                ->map(fn (Brand $b) => ['id' => $b->id, 'handle' => $b->handle, 'name' => $b->name])
                ->values()
                ->all();

            Statamic::provideToScript([
                'brandContext' => [
                    'brands' => $brands,
                    'current' => $manager->currentId(),
                ],
            ]);
        } catch (Throwable) {
            // Never let the switcher data break a CP request.
        }
    }
}
