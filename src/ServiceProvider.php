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

        $cpPrefix = config('statamic.cp.route', 'cp');

        // Switcher routes, mounted under the CP with its auth/session middleware.
        // Registering the group here (not via pushCpRoutes) is reliable
        // regardless of provider boot order.
        \Illuminate\Support\Facades\Route::middleware('statamic.cp')
            ->prefix($cpPrefix)
            ->name('brand-context.')
            ->group(__DIR__.'/../routes/cp.php');

        // Every CP request must resolve the active brand from the session,
        // otherwise the fail-closed scope hides all data. Push after the app has
        // booted so the statamic.cp middleware group already exists.
        $this->app->booted(function () {
            $this->app['router']->pushMiddlewareToGroup('statamic.cp', SetBrandFromSession::class);
        });

        \Statamic\Facades\CP\Nav::extend(function ($nav) {
            $nav->tools(__('Brands'))
                ->route('brand-context.switcher.index')
                ->icon('shield');
        });
    }
}
