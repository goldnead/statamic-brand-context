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
use Goldnead\BrandContext\Settings\NavPlacement;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsNavChild;
use Goldnead\BrandContext\Settings\SettingsNavSuite;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Statamic\Contracts\Auth\UserRepository as UserRepositoryContract;
use Statamic\CP\Navigation\NavItem;
use Statamic\Events\UserBlueprintFound;
use Statamic\Events\UserSaved;
use Statamic\Events\UserSaving;
use Statamic\Facades\CP\Nav;
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

        // The brand field on Statamic's user form. A singleton because it
        // carries the value it lifted off a user in `UserSaving` across to
        // `UserSaved`; two instances would drop it on the floor.
        $this->app->singleton(UserBrandField::class);

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

        // Registered in both modes, unlike everything below the guard: the
        // field decides for itself whether it has anything to say (see
        // UserBrandField::applies()), and deciding that once at boot would
        // mean an install that turns multi-brand on, or adds its second brand,
        // keeps a user form without the field until the next deploy.
        $this->registerUserBrandField();

        $this->publishes([
            __DIR__.'/../resources/dist/build' => public_path('vendor/statamic-brand-context/build'),
        ], 'brand-context-cp');

        // Und veroeffentlichen, ohne dass jemand daran denkt.
        //
        // Dieses Paket erweitert Illuminates ServiceProvider, nicht Statamics
        // AddonServiceProvider — also greift keine der Addon-Konventionen, und
        // `statamic:install` (der Composer-Hook jeder Site) veroeffentlicht das
        // CP-Bundle nicht mit. Ergebnis auf staging.adriangoldner.com:
        // `public/vendor/statamic-brand-context/` existierte nie, die
        // Vite-Anmeldung oben stieg wegen des fehlenden Manifests aus, das
        // Bundle wurde nie geladen, und die Einstellungsseite blieb weiss mit
        // „Couldn't find Inertia component for the [brand-context::Settings]
        // page". Zwanzig andere Addons lagen daneben, weil sie die Konvention
        // nutzen. Gemessen 22.09.2026.
        Statamic::afterInstalled(function ($command): void {
            $command->call('vendor:publish', ['--tag' => 'brand-context-cp', '--force' => true]);
        });

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
    }

    /**
     * The suite's settings screen. An addon with a Control Panel navigation of
     * its own gets a "Settings" child under that entry, opening a page for that
     * addon alone; every other addon is a tab on one general page with a single
     * sidebar entry. {@see extendNavWithSettings()}.
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

        $this->app->scoped(NavPlacement::class);

        // Als Letztes angemeldet, und das ist die Reihenfolge, auf die es
        // ankommt: Statamic ruft die `Nav::extend()`-Rueckrufe in der
        // Reihenfolge ihrer Anmeldung auf, und ein Eltern-Eintrag, der noch
        // nicht existiert, ist nicht zu finden. Die Addons melden ihre Nav aus
        // `bootAddon()` an, und das laeuft in einem `Statamic::booted()`-Rueckruf,
        // den Statamics eigener Provider erst nach dem Booten abarbeitet.
        // Gemessen im Playground (Oktober 2026): ein `Nav::extend()` aus
        // `app->booted()` stand an Stelle 0 von 30, vor allen Addons, und fand
        // keinen einzigen Eltern-Eintrag.
        //
        // Deshalb wird aus `app->booted()` heraus noch einmal in die Warteschlange
        // von `Statamic::booted()` eingereiht: hinter alle Addons, die sich dort
        // schon gemeldet haben.
        $this->app->booted(function () {
            $extend = fn () => Nav::extend(fn ($nav) => $this->extendNavWithSettings($nav));

            if ($this->statamicBootedCallbacksPending()) {
                Statamic::booted($extend);

                return;
            }

            // Statamics Warteschlange ist schon abgearbeitet (oder es gibt sie
            // nicht): die Addons haben sich gemeldet, direkt anmelden.
            $extend();
        });
    }

    /**
     * Stehen noch `Statamic::booted()`-Rueckrufe aus?
     *
     * Statamic bietet dafuer keine Frage an; die Warteschlange ist eine
     * geschuetzte statische Eigenschaft. Gelesen, nicht veraendert, und jeder
     * Fehlschlag heisst "nein": dann wird direkt angemeldet, und im
     * schlechtesten Fall steht der Rueckruf vor einem Addon, das dann auf der
     * allgemeinen Seite landet, nicht nirgends.
     */
    protected function statamicBootedCallbacksPending(): bool
    {
        try {
            $callbacks = (new \ReflectionProperty(Statamic::class, 'bootedCallbacks'))->getValue();

            return is_array($callbacks) && $callbacks !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Die Einstellungen in die Seitenleiste haengen.
     *
     * Ein Addon mit eigener CP-Navigation bekommt einen Unterpunkt unter
     * seinem Eintrag (`settingsNavParent()`), alle anderen teilen sich einen
     * einzigen Eintrag in der Sektion "Settings", die allgemeine Seite der
     * Suite. Findet sich der Eltern-Eintrag nicht, oder darf der Benutzer ihn
     * nicht sehen, gilt das Addon als eines ohne eigene Navigation: nie ein
     * Addon unerreichbar machen. {@see NavPlacement}.
     */
    protected function extendNavWithSettings($nav): void
    {
        $registry = $this->app->make(SettingsRegistry::class);
        $placement = $this->app->make(NavPlacement::class);

        // Nothing registered means no screen. An empty settings page is
        // worse than no link: it reads as "the suite has no settings"
        // rather than as "no installed addon offers any".
        if ($registry->all() === []) {
            return;
        }

        // An entry is built per addon the operator may actually manage,
        // because a nav entry leading to a page with nothing on it is a
        // dead end. NavItem::can() takes a single permission and there are
        // as many here as there are addons, so the check is done in the
        // open rather than handed to it.
        $user = User::current();

        // `can()` is on the Statamic user implementation, not on the
        // contract the facade is typed against. Guarded rather than
        // assumed, the same way every other authorisation check in this
        // package is: a host that swaps the user repository must not take
        // the Control Panel down with a fatal from the nav builder.
        if ($user === null || ! method_exists($user, 'can')) {
            return;
        }

        $general = false;

        // `sorted()`, so an operator finds the same order twice.
        foreach (array_keys($registry->sorted()) as $namespace) {
            $permission = $registry->permission($namespace);

            if ($permission === null || ! $user->can($permission)) {
                continue;
            }

            $parent = $placement->parentItem($nav, $registry->navParent($namespace), $user);

            if ($parent === null) {
                $placement->remember($namespace, false);
                $general = true;

                continue;
            }

            $placement->remember($namespace, true);

            // `?section=`, which is the whole difference between this and the
            // child entry `statamic-automations` removed in 2026 after a menu
            // item that merely forwards somewhere was reported as a bug: this
            // one opens the page of the addon it sits under.
            // {@see BrandSettingsController::index()}.
            $child = (new SettingsNavChild)
                ->forNamespace($namespace)
                ->display(__('brand-context::messages.nav_settings_child'))
                ->route('brand-context.settings.index', ['section' => $namespace])
                ->icon($registry->icon($namespace));

            $this->appendNavChild($parent, $child);
        }

        if (! $general) {
            return;
        }

        // Statamic kennt nur `create()` zum Anmelden, und das baut ein NavItem.
        // Wir brauchen die Unterklasse (siehe SettingsNavSuite), also wird das
        // soeben angelegte Item gegen sie getauscht.
        $created = $nav->create(__('brand-context::messages.nav_suite'));
        $suite = (new SettingsNavSuite)->display($created->display());

        $property = new \ReflectionProperty($nav, 'items');
        $items = $property->getValue($nav);
        $items[array_key_last($items)] = $suite;
        $property->setValue($nav, $items);

        $suite->section('Settings')
            ->route('brand-context.settings.index')
            ->icon('sliders-horizontal');
    }

    /**
     * Ein Kind an einen fremden Eintrag haengen, ohne dessen eigene Kinder zu
     * verlieren. Die Kinder koennen ein Closure (erst beim Aufklappen
     * aufgeloest), eine fertige Liste oder gar nichts sein.
     */
    protected function appendNavChild(NavItem $parent, NavItem $child): void
    {
        $existing = $parent->children();

        // Ein Closure wird hier aufgeloest, nicht eingewickelt. Statamic merkt sich
        // die URLs ungeloester Kinder ohne Query und haelt den Eltern-Eintrag fuer
        // aktiv, sobald irgendein Unterpunkt dieselbe Route hat: alle Addons mit
        // eigener Nav leuchteten auf der Einstellungsseite auf. Die Kosten sind,
        // dass das Closure bei jedem Aufbau der Nav laeuft statt erst beim
        // Aufklappen. Wirft es, bleibt es ein Closure, mit dem Kind darin.
        if (is_callable($existing)) {
            try {
                $existing = $existing();
            } catch (\Throwable) {
                $parent->children(function () use ($existing, $child) {
                    $items = $existing();
                    $items = $items instanceof Collection ? $items->all() : (array) $items;
                    $items[] = $child;

                    return $items;
                });

                return;
            }
        }

        $items = $existing === null ? [] : collect($existing)->all();
        $items[] = $child;

        $parent->children($items);
    }

    /**
     * Brand affiliation on Statamic's own user form.
     *
     * Replaces the screen this package used to register under its own nav item
     * — see {@see UserBrandField} for why that screen could not be understood
     * and what the three hooks below do. There is no route, no page component
     * and no permission of our own any more: editing a user is already gated
     * by core's `edit users`, and a second permission on the same form would
     * only make it ambiguous which one is being granted.
     */
    protected function registerUserBrandField(): void
    {
        // `class_exists(Statamic::class)` is true whenever the CMS is merely
        // installed — it is a dependency of this package. That is not the same
        // as the CMS being *booted*: in a plain Laravel host, and in this
        // package's own Statamic-less suite, nothing binds the users
        // repository, and `User::computed()` would fatal from inside boot().
        if (! $this->app->bound(UserRepositoryContract::class)) {
            return;
        }

        $field = $this->app->make(UserBrandField::class);

        // The listeners return null, deliberately. UserSaving::dispatch halts
        // on the first non-null response, and a listener that answered would
        // cancel everyone else's save.
        Event::listen(UserBlueprintFound::class, function ($event) use ($field) {
            $field->addTo($event->blueprint);
        });

        Event::listen(UserSaving::class, function ($event) use ($field) {
            $field->takeFrom($event->user);
        });

        Event::listen(UserSaved::class, function ($event) use ($field) {
            $field->sync($event->user);
        });

        // The read side. `ExtractsFromUserFields` merges computedData() over
        // the user's own data, which is how a field with nothing in storage
        // still arrives at the form with a value.
        //
        // Deferred to `booted`, and that is not cosmetic. `User::computed()`
        // resolves the users repository, and the file driver's repository is a
        // singleton built around `Stache::store('users')`. Called from this
        // provider's boot() it is built before Statamic has registered that
        // store, so it keeps a null store for the rest of the process and
        // every `User::all()` afterwards dies in the query builder — in the
        // Control Panel, in the console, everywhere.
        $this->app->booted(function () use ($field) {
            User::computed(UserBrandField::HANDLE, fn ($user) => $field->currentValue($user));
        });
    }
}
