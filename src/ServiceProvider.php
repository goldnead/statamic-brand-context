<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Goldnead\BrandContext\Contracts\SenderIdentityResolver;
use Goldnead\BrandContext\Contracts\UserSource;
use Goldnead\BrandContext\Http\Middleware\ResolveBrandFromToken;
use Goldnead\BrandContext\Http\Middleware\SetBrandForSite;
use Goldnead\BrandContext\Http\Middleware\SetBrandFromSession;
use Goldnead\BrandContext\Queue\BrandOnQueue;
use Goldnead\BrandContext\Sending\BrandSenderIdentity;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Statamic;

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

        // The settings layer. Both singletons: the registry is the process's
        // list of which addons announced a screen, and the manager holds the
        // packaged-config baseline, which must be captured once before any
        // override is applied and never recaptured.
        $this->app->singleton(SettingsRegistry::class);

        $this->app->singleton('brand-context.settings', fn ($app) => new SettingsManager(
            $app->make(SettingsRegistry::class),
            $app->make(BrandManager::class),
            $app->make('config'),
        ));

        $this->app->alias('brand-context.settings', SettingsManager::class);

        // Who a brand's mail goes out as, and over which transport. Bound
        // rather than singleton because it reads brand rows and config on
        // every call, and an identity resolved once at boot would outlive the
        // brand it described.
        //
        // Addons that send mail bind their own sub-interface to their own
        // subclass, so a host can answer the question differently per addon.
        // This binding is the answer for anything that asks the base contract.
        $this->app->bind(SenderIdentityResolver::class, BrandSenderIdentity::class);

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
        $this->registerQueue();
        $this->registerControlPanel();
        $this->registerSettings();
    }

    /**
     * Put the stored overrides onto the live config, and keep them in step
     * with the brand switcher.
     *
     * Deferred to `booted` so every addon provider has had its own `boot()` to
     * register with {@see SettingsRegistry}: applying earlier would push the
     * overrides of whichever packages happened to boot first and silently skip
     * the rest.
     */
    protected function registerSettings(): void
    {
        $this->app->booted(function () {
            // `config:cache` boots the app fully and then dumps the resolved
            // config to `bootstrap/cache/config.php`. Applying during that
            // build would bake the overrides into the cached file, and a baked
            // override outlives the row it came from: deleting a setting would
            // have no effect at all until somebody ran `config:clear`.
            //
            // It would also poison the baseline. On the next boot
            // `mergeConfigFrom` is skipped because the config is cached, so
            // the baseline snapshot would record an override as the packaged
            // default — and a value reset to the file's default would then be
            // stored as a row instead of deleted.
            //
            // Skipping is safe: the cached config keeps the file values, and
            // every process that reads it applies the overrides on its own
            // boot.
            if (method_exists($this->app, 'runningConsoleCommand')
                && $this->app->runningConsoleCommand('config:cache')) {
                return;
            }

            $settings = $this->app->make(SettingsManager::class);

            $settings->apply();

            // The live config belongs to whichever brand was applied last, so
            // a switch has to be pushed through before anything reads config()
            // again — in the Control Panel, in a queue worker taking the next
            // brand's job, and inside RunsForEachBrand.
            $this->app->make(BrandManager::class)
                ->onBrandChanged(fn () => $settings->brandChanged());
        });
    }

    /**
     * Carry the current brand into queued jobs.
     *
     * Outside a request nothing resolves a brand, and fail-closed turns that
     * into "no rows" rather than "all rows" — a queued job then runs to
     * completion having seen nothing. {@see BrandOnQueue} explains what
     * that cost on a live install.
     */
    protected function registerQueue(): void
    {
        // Als Singleton: der prozessweite Payload-Haken löst diese Klasse zur
        // Laufzeit aus dem aktuellen Container auf, und der Stapel, auf dem die
        // vorherige Marke liegt, muss derselbe sein wie der, den die
        // Job-Ereignisse füllen.
        $this->app->singleton(BrandOnQueue::class);
        $this->app->make(BrandOnQueue::class)->register($this->app['events']);
    }

    /**
     * Places a brand resolver directly before SubstituteBindings in a group,
     * falling back to appending when that middleware is not present.
     */
    protected function insertBeforeBindings(string $group, string $middleware = SetBrandFromSession::class): void
    {
        $router = $this->app['router'];
        $stack = $router->getMiddlewareGroups()[$group] ?? [];

        if (in_array($middleware, $stack, true)) {
            return;
        }

        $at = array_search(SubstituteBindings::class, $stack, true);

        if ($at === false) {
            // Appending is the only thing left to do, but it reintroduces exactly the bug this
            // method exists to prevent: the brand is resolved after route-model binding, so every
            // bound lookup in every dependent addon fails closed and 404s. Silence here would make
            // that look like an addon bug rather than a middleware-ordering one.
            $message = sprintf(
                'brand-context: SubstituteBindings was not found in the [%s] middleware group, so '
                .'%s had to be appended. Route-model binding now runs before the brand is resolved, '
                .'which makes bound routes 404 in multi-brand mode.',
                $group,
                class_basename($middleware)
            );

            if ($this->app->environment('local', 'testing')) {
                throw new \RuntimeException($message);
            }

            report(new \RuntimeException($message));

            $router->pushMiddlewareToGroup($group, $middleware);

            return;
        }

        array_splice($stack, $at, 0, [$middleware]);
        $router->middlewareGroup($group, $stack);
    }

    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('brand.token', ResolveBrandFromToken::class);
        $router->aliasMiddleware('brand.session', SetBrandFromSession::class);
        $router->aliasMiddleware('brand.site', SetBrandForSite::class);
    }

    /**
     * Wire the CP brand switcher — only when the Statamic CP is present and
     * multi-brand is active. Kept fully optional so the core package boots in a
     * plain Laravel context (and in tests) without Statamic.
     */
    protected function registerControlPanel(): void
    {
        if (! class_exists(Statamic::class)) {
            return;
        }

        $multiBrand = app('brand-context')->multiBrandEnabled();

        // The Control Panel bundle, the settings screen and the assets are
        // registered in **both** modes. The settings screen is the reason: a
        // single-brand install is the common case and the one an agency buys
        // the suite for, and a settings screen that only appears once you turn
        // on multi-brand would be a settings screen almost nobody sees.
        //
        // Only the brand switcher and the request-scoped brand resolution are
        // multi-brand concerns, and they stay behind the flag below.
        // Registered only when the published bundle is actually there.
        // `partials/scripts.blade.php` calls `Vite::withEntryPoints()` for
        // every registered vite with no guard of its own, and a missing
        // manifest throws from inside the Control Panel layout — which is a
        // 500 on *every* CP page, not just this addon's.
        //
        // That risk used to be confined to multi-brand installs, because the
        // bundle was only registered there. Since the settings screen made it
        // load everywhere, an existing single-brand site that upgrades without
        // running `vendor:publish --tag=brand-context-cp` would have lost its
        // whole Control Panel. A settings screen that renders nothing is a bad
        // day; a Control Panel that will not open is a worse one.
        $hot = __DIR__.'/../resources/dist/hot';

        if (file_exists($hot) || is_file(public_path('vendor/statamic-brand-context/build/manifest.json'))) {
            Statamic::vite('statamic-brand-context', [
                'buildDirectory' => 'vendor/statamic-brand-context/build',
                'input' => ['resources/js/cp.js'],
                // The package's own path, not public_path(): `npm run dev` writes the hot file next to
                // the bundle it builds (vite.config.js sets the same path). Pointing at public_path()
                // meant the CP looked somewhere Vite never writes, so HMR silently did nothing.
                'hotFile' => $hot,
            ]);
        }

        // Whether the switcher should mount at all. Without this the bundle
        // would append it on every single-brand install, where it has no
        // brands to offer and its own config key is never provided — the
        // component would log the "middleware is missing" error on a site
        // where nothing is missing.
        Statamic::provideToScript(['brandContextMultiBrand' => $multiBrand]);

        $this->registerSettingsScreen();

        $this->publishes([
            __DIR__.'/../resources/dist/build' => public_path('vendor/statamic-brand-context/build'),
        ], 'brand-context-cp');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/brand-context'),
        ], 'brand-context-translations');

        if (! $multiBrand) {
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

            // And the website. Without this a multi-brand installation serves
            // empty pages: a visitor has no session, so nothing sets a brand,
            // and the fail-closed scope hides every row — silently, on every
            // page. Which brand a request belongs to is answered by
            // `brand-context.sites` or `.hosts`; see the config file.
            foreach (['statamic.web', 'web'] as $gruppe) {
                if (isset($this->app['router']->getMiddlewareGroups()[$gruppe])) {
                    $this->insertBeforeBindings($gruppe, SetBrandForSite::class);
                }
            }
        });

        $this->registerMembershipScreen();
    }

    /**
     * The suite's settings screen: one page, one section per registered addon.
     *
     * Registered in both single- and multi-brand mode, unlike the membership
     * screen below. A single-brand install still has settings; what it does
     * not have is more than one brand to keep them apart for.
     *
     * The permissions themselves are **not** registered here. Each addon
     * already ships its own (`manage automation settings` and friends), and
     * registering them again from this package would produce a duplicate entry
     * in the permission tree and make it ambiguous which one a user group is
     * actually granting.
     */
    protected function registerSettingsScreen(): void
    {
        Statamic::pushCpRoutes(function () {
            Route::group([], __DIR__.'/../routes/cp-settings.php');
        });

        Nav::extend(function ($nav) {
            $registry = $this->app->make(SettingsRegistry::class);

            // Nothing registered means no screen. An empty settings page is
            // worse than no link: it reads as "the suite has no settings"
            // rather than as "no installed addon offers any".
            if ($registry->all() === []) {
                return;
            }

            // The item is built only when the operator can manage at least one
            // section, because a nav entry leading to a page with nothing on
            // it is a dead end. NavItem::can() takes a single permission and
            // there are as many here as there are addons, so the check is done
            // in the open rather than handed to it.
            $user = User::current();

            // `can()` is on the Statamic user implementation, not on the
            // contract the facade is typed against. Guarded rather than
            // assumed, the same way every other authorisation check in this
            // package is: a host that swaps the user repository must not take
            // the Control Panel down with a fatal from the nav builder.
            if ($user === null || ! method_exists($user, 'can')) {
                return;
            }

            foreach (array_keys($registry->all()) as $namespace) {
                $permission = $registry->permission($namespace);

                if ($permission !== null && $user->can($permission)) {
                    $nav->create(__('brand-context::messages.nav_settings'))
                        ->section('Settings')
                        ->route('brand-context.settings.index')
                        ->icon('sliders-horizontal');

                    return;
                }
            }
        });
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
        Statamic::pushCpRoutes(function () {
            Route::group([], __DIR__.'/../routes/cp.php');
        });

        Permission::extend(function () {
            Permission::group('brand-context', __('brand-context::messages.permission_group'), function () {
                Permission::register('manage brand members')
                    ->label(__('brand-context::messages.manage_brand_members'));
            });
        });

        Nav::extend(function ($nav) {
            $nav->create(__('brand-context::messages.nav_brand_members'))
                ->section('Users')
                ->route('brand-context.users.index')
                ->icon('users')
                ->can('manage brand members');
        });
    }
}
