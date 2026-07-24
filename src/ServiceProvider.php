<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Goldnead\BrandContext\Http\Middleware\ResolveBrandFromToken;
use Goldnead\BrandContext\Http\Middleware\SetBrandFromSession;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brand-context.php', 'brand-context');

        $this->app->singleton('brand-context', fn () => new BrandManager);
        $this->app->alias('brand-context', BrandManager::class);

        $this->app->bind(BrandTokenResolver::class, DatabaseBrandTokenResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'brand-context');

        $this->publishes([
            __DIR__.'/../config/brand-context.php' => config_path('brand-context.php'),
        ], 'brand-context-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'brand-context-migrations');

        $this->registerMiddleware();
        $this->registerControlPanel();
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('brand.token', ResolveBrandFromToken::class);
        $router->aliasMiddleware('brand.session', SetBrandFromSession::class);
    }

    /**
     * Wire the CP brand switcher — only when the Statamic CP is present and
     * multi-brand is active. Kept fully optional so the core package boots in a
     * plain Laravel context (and in tests) without Statamic.
     */
    protected function registerControlPanel(): void
    {
        if (! class_exists(\Statamic\Statamic::class)) {
            return;
        }

        if (! app('brand-context')->multiBrandEnabled()) {
            return;
        }

        // Every CP request must resolve the active brand from the session,
        // otherwise the fail-closed scope hides all data. Push after the app has
        // booted so the statamic.cp middleware group already exists. Switching is
        // a plain ?brand=<handle> GET the middleware handles — no extra page.
        $this->app->booted(function () {
            $this->app['router']->pushMiddlewareToGroup('statamic.cp', SetBrandFromSession::class);
        });

        // Native CP nav: a "Brands" group in the Tools section with one child per
        // brand. Clicking a child switches the active brand in place. The parent
        // label shows the current brand at a glance.
        \Statamic\Facades\CP\Nav::extend(function ($nav) {
            // A failure here must never take down the whole CP (the nav renders
            // on every page). Log it and skip the item instead of 500ing.
            try {
                $manager = app('brand-context');
                $brands = \Goldnead\BrandContext\Models\Brand::query()
                    ->orderByDesc('is_default')->orderBy('name')->get();

                if ($brands->isEmpty()) {
                    return;
                }

                $currentId = $manager->hasCurrent() ? $manager->currentId() : $manager->defaultId();
                $base = cp_route('index');

                $children = $brands->map(fn ($brand) => $nav
                    ->item($brand->name.($brand->id === $currentId ? '  ✓' : ''))
                    ->url($base.'?brand='.$brand->handle)
                )->all();

                $active = optional($brands->firstWhere('id', $currentId));

                $nav->create(__('Brands').($active ? ': '.$active->name : ''))
                    ->section('Tools')
                    ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/></svg>')
                    ->url($base.'?brand='.($active->handle ?? $brands->first()->handle))
                    ->children($children);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('brand-context: CP nav build failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
