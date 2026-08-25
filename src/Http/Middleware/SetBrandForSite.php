<?php

namespace Goldnead\BrandContext\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The website's counterpart to {@see SetBrandFromSession}.
 *
 * Without it a multi-brand installation serves an empty website. The scope
 * fails closed by design, and a public request has no session to read a brand
 * from — so every scoped query returns nothing, on every page, with no error,
 * no exception and no log line. Nothing looks broken; the site simply has no
 * content. That is the worst shape a bug can take, and it existed because the
 * middleware was only ever wired into the `statamic.cp` group.
 *
 * Resolution order, most specific first:
 *
 *   1. the Statamic site — one site per brand is the tidiest arrangement, and
 *      the only one that also gives each brand its own URLs and locale.
 *   2. the host — for installations that serve several domains from one site.
 *   3. `?brand=` — off by default. On a public page this is a way to read
 *      another brand's data by guessing a handle, so it stays a deliberate
 *      choice for previews and demos rather than a convenience.
 *   4. the default brand, once, loudly. A site that lands here is misconfigured
 *      and should hear about it rather than quietly serve the wrong brand.
 */
class SetBrandForSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return $next($request);
        }

        $handle = $this->fromSite()
            ?? $this->fromHost($request)
            ?? $this->fromPath($request)
            ?? $this->fromQuery($request);

        if ($handle !== null && $this->apply($manager, $handle, $request)) {
            return $next($request);
        }

        $this->fallBack($manager, $request);

        return $next($request);
    }

    /** One Statamic site per brand: `sites` maps site handle to brand handle. */
    protected function fromSite(): ?string
    {
        $site = $this->siteHandle();

        return $site === null ? null : (config('brand-context.sites')[$site] ?? null);
    }

    /**
     * Which Statamic site is answering, if any.
     *
     * Wrapped because asking can throw for reasons that have nothing to do with
     * brands — a filesystem disk that is not configured, for one — and a
     * middleware that decides which rows a visitor sees must not be the thing
     * that takes the page down.
     */
    protected function siteHandle(): ?string
    {
        if (! class_exists(Site::class)) {
            return null;
        }

        try {
            return Site::current()?->handle();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Several domains on one site: `hosts` maps a host to a brand handle.
     *
     * The port is stripped so a local `:8099` matches the same entry the live
     * domain does, and a leading `www.` is tried as well — a visitor typing it
     * must not land on another brand's content.
     */
    protected function fromHost(Request $request): ?string
    {
        $hosts = config('brand-context.hosts', []);

        if ($hosts === []) {
            return null;
        }

        $host = mb_strtolower($request->getHost());

        return $hosts[$host]
            ?? $hosts[preg_replace('/^www\./', '', $host)]
            ?? null;
    }

    /**
     * Several brands on one domain, told apart by the first path segment.
     *
     * `paths` maps that segment to a brand handle. Only the first segment is
     * looked at: a brand owns a prefix, not a scattering of URLs, and matching
     * deeper would make `/chorwerkstatt/kurse/vinyl` ambiguous the moment two
     * brands share a word.
     */
    protected function fromPath(Request $request): ?string
    {
        $paths = config('brand-context.paths', []);

        if ($paths === []) {
            return null;
        }

        $erstes = explode('/', trim($request->path(), '/'))[0] ?? '';

        return $erstes === '' ? null : ($paths[$erstes] ?? null);
    }

    protected function fromQuery(Request $request): ?string
    {
        return config('brand-context.allow_query_override', false)
            ? $request->query('brand')
            : null;
    }

    protected function apply(mixed $manager, string $handle, Request $request): bool
    {
        try {
            $manager->setCurrent($handle);

            return true;
        } catch (Throwable) {
            // A handle that names no brand is a configuration error, not a
            // reason to serve another brand's content silently.
            Log::warning('brand-context: the request named a brand that does not exist.', [
                'handle' => $handle,
                'host' => $request->getHost(),
            ]);

            return false;
        }
    }

    /**
     * The last resort, and it says so.
     *
     * Once per request at warning level, because the alternative — an empty
     * site — took a full inventory to notice. If a site really wants the
     * default everywhere, `hosts` or `sites` says that in one line and the
     * warning stops.
     */
    protected function fallBack(mixed $manager, Request $request): void
    {
        try {
            $manager->setCurrent(config('brand-context.default_handle', 'default'));
        } catch (Throwable) {
            // No default either. Leaving the request without a brand is
            // correct here: fail-closed then hides the data, which is the safe
            // half of a bad situation.
            Log::error('brand-context: no brand could be resolved for a website request, and the default handle names no brand either. The scope will hide everything.', [
                'host' => $request->getHost(),
                'path' => $request->path(),
            ]);

            return;
        }

        Log::warning('brand-context: no brand is mapped to this request, so the default was used. Map it in brand-context.sites or brand-context.hosts.', [
            'host' => $request->getHost(),
            'site' => $this->siteHandle(),
        ]);
    }
}
