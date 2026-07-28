<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Goldnead\BrandContext\Contracts\UserSource;
use Goldnead\BrandContext\Http\Middleware\ResolveBrandFromToken;
use Goldnead\BrandContext\Http\Middleware\SetBrandFromSession;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/brand-context.php', 'brand-context');

        $this->app->singleton('brand-context', fn () => new BrandManager);
        $this->app->alias('brand-context', BrandManager::class);

        $this->app->bind(BrandTokenResolver::class, DatabaseBrandTokenResolver::class);

        // Who belongs to which brand. A singleton so a host application can
        // swap the user source once and have every consumer see it.
        $this->app->singleton('brand-context.members', fn ($app) => new BrandMembership($app['brand-context']));
        $this->app->alias('brand-context.members', BrandMembership::class);

        $this->app->bind(UserSource::class, StatamicUserSource::class);

        // Registered on `resolving` as well as directly, because the translator
        // may already be resolved by the time an addon provider registers.
        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('brand-context', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('brand-context', $langPath);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // brand-context ships no Blade views (JS/CSS addon). Registering a
        // non-existent views path makes `php artisan view:cache` (deploy
        // `optimize`) fail with "directory does not exist" — guard it.
        if (is_dir(__DIR__.'/../resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'brand-context');
        }

        $this->publishes([
            __DIR__.'/../config/brand-context.php' => config_path('brand-context.php'),
        ], 'brand-context-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'brand-context-migrations');

        $this->registerMiddleware();
        $this->registerControlPanel();
    }

    /**
     * Places SetBrandFromSession directly before SubstituteBindings in a group,
     * falling back to appending when that middleware is not present.
     */
    protected function insertBeforeBindings(string $group): void
    {
        $router = $this->app['router'];
        $stack = $router->getMiddlewareGroups()[$group] ?? [];

        if (in_array(SetBrandFromSession::class, $stack, true)) {
            return;
        }

        $at = array_search(SubstituteBindings::class, $stack, true);

        if ($at === false) {
            $router->pushMiddlewareToGroup($group, SetBrandFromSession::class);

            return;
        }

        array_splice($stack, $at, 0, [SetBrandFromSession::class]);
        $router->middlewareGroup($group, $stack);
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
        // otherwise the fail-closed scope hides all data. Registered after the
        // app has booted, so the statamic.cp group already exists.
        //
        // It has to run BEFORE SubstituteBindings. Route-model binding resolves
        // `{automation}`, `{delivery}` and friends through the query builder; if
        // no brand is current at that moment the scope fails closed, the lookup
        // finds nothing and the request dies as a 404 — every edit, delete and
        // detail page in every addon with bound models. `pushMiddlewareToGroup`
        // always appends, which put us behind it, so the group is rebuilt.
        $this->app->booted(function () {
            $this->insertBeforeBindings('statamic.cp');
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

        $this->registerMembershipScreen();

        $this->publishes([
            __DIR__.'/../resources/dist/build' => public_path('vendor/statamic-brand-context/build'),
        ], 'brand-context-cp');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/brand-context'),
        ], 'brand-context-translations');
    }

    /**
     * The screen that assigns Control Panel users to the current brand.
     *
     * Only reachable under multi-brand — the whole call site is inside the
     * multi-brand guard of registerControlPanel(). A single-brand install has
     * one brand, so a membership screen there would offer a choice that does
     * not exist, and every user is a member of it anyway.
     *
     * Routes go through Statamic::pushCpRoutes() rather than a route file on an
     * AddonServiceProvider: this package is a plain Laravel provider on purpose
     * (it has to boot in a Statamic-less context), and pushCpRoutes is the same
     * mechanism AddonServiceProvider::registerCpRoutes() uses underneath.
     */
    protected function registerMembershipScreen(): void
    {
        \Statamic\Statamic::pushCpRoutes(function () {
            Route::group([], __DIR__.'/../routes/cp.php');
        });

        \Statamic\Facades\Permission::extend(function () {
            \Statamic\Facades\Permission::group('brand-context', __('brand-context::messages.permission_group'), function () {
                \Statamic\Facades\Permission::register('manage brand members')
                    ->label(__('brand-context::messages.manage_brand_members'));
            });
        });

        \Statamic\Facades\CP\Nav::extend(function ($nav) {
            $nav->create(__('brand-context::messages.nav_brand_members'))
                ->section('Users')
                ->route('brand-context.users.index')
                ->icon('users')
                ->can('manage brand members');
        });
    }
}
