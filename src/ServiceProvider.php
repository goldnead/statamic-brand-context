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
        // otherwise the fail-closed scope hides all data. The same middleware
        // also hands the brand list + current brand to the CP JS. Push after the
        // app has booted so the statamic.cp middleware group already exists.
        $this->app->booted(function () {
            $this->app['router']->pushMiddlewareToGroup('statamic.cp', SetBrandFromSession::class);
        });

        // Load the CP brand-switcher bundle (a global Vue component that floats a
        // brand selector into the top-right of the CP header — the supported way,
        // since Statamic exposes no addon slot for the native user menu/topbar).
        // Built into resources/dist/build, published to public/vendor/….
        \Statamic\Statamic::vite('statamic-brand-context', [
            'buildDirectory' => 'vendor/statamic-brand-context/build',
            'input' => ['resources/js/cp.js'],
            'hotFile' => public_path('vendor/statamic-brand-context/hot'),
        ]);

        $this->publishes([
            __DIR__.'/../resources/dist/build' => public_path('vendor/statamic-brand-context/build'),
        ], 'brand-context-cp');
    }
}
