<?php

use Goldnead\BrandContext\Http\Middleware\GuardSetup;
use Goldnead\BrandContext\ServiceProvider;
use Goldnead\BrandContext\Tests\Fixtures\Ghost;
use Goldnead\BrandContext\Tests\Fixtures\RecordsOrder;
use Goldnead\BrandContext\Tests\Fixtures\SetupStub;
use Goldnead\BrandContext\Tests\Fixtures\SetupStubReturningNull;
use Goldnead\BrandContext\Tests\Fixtures\SetupStubReturningString;
use Goldnead\BrandContext\Tests\Fixtures\Widget;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * A detail route binds its model by type hint, so the query has run before the
 * controller starts. A guard inside the method comes too late; the middleware
 * has to stand in front of SubstituteBindings.
 */
beforeEach(function () {
    SetupStub::$calls = [];
    RecordsOrder::$log = [];
    RecordsOrder::$refuse = [];

    // The unguarded route: today's behaviour, and the reason the middleware exists.
    Route::middleware('web')->get('/bare/{ghost}', fn (Ghost $ghost) => 'unreachable');

    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Ghosts', 'ghosts_never_migrated')])
        ->get('/guarded/{ghost}', fn (Ghost $ghost) => 'unreachable');

    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Widgets', 'widgets')])
        ->get('/ready/{widget}', fn (Widget $widget) => 'widget '.$widget->id);
});

it('crashes on a bound detail route when the table is missing and nothing guards it', function () {
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/bare/1'))->toThrow(QueryException::class);
});

it('shows the setup screen instead of the query error, before the binding runs', function () {
    $this->withoutExceptionHandling();

    $this->get('/guarded/1')
        ->assertOk()
        ->assertSee('setup required: Ghosts [ghosts_never_migrated]', false);
});

it('hands the request on when the tables exist', function () {
    $widget = Widget::create(['email' => 'a@example.com']);

    $this->get('/ready/'.$widget->id)
        ->assertOk()
        ->assertSee('widget '.$widget->id);
});

it('still 404s a missing record when the tables exist', function () {
    $this->get('/ready/999999')->assertNotFound();
});

it('keeps a title with commas and a backslashed class name intact', function () {
    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Rights, roles and more', 'ghosts_never_migrated', 'widgets')])
        ->get('/comma/{ghost}', fn (Ghost $ghost) => 'unreachable');

    $this->get('/comma/1')->assertSee('setup required: Rights, roles and more [ghosts_never_migrated]', false);

    expect(SetupStub::$calls[0])->toBe(['Rights, roles and more', ['ghosts_never_migrated', 'widgets']]);
});

it('translates the title per request, so a cached route does not freeze the locale', function () {
    app('translator')->addLines(['messages.ghost_nav' => 'Geister'], 'de');
    app('translator')->addLines(['messages.ghost_nav' => 'Ghosts'], 'en');

    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'messages.ghost_nav', 'ghosts_never_migrated')])
        ->get('/translated/{ghost}', fn (Ghost $ghost) => 'unreachable');

    app()->setLocale('de');

    $this->get('/translated/1')->assertSee('setup required: Geister', false);
});

it('refuses a setup class that has no guard method, loudly', function () {
    $this->withoutExceptionHandling();

    Route::middleware(['web', GuardSetup::using(stdClass::class, 'X', 'widgets')])
        ->get('/broken/{widget}', fn (Widget $widget) => 'unreachable');

    expect(fn () => $this->get('/broken/1'))->toThrow(InvalidArgumentException::class);
});

/*
 * In the Control Panel, "before SubstituteBindings" is also "before the login check and the
 * navigation". These groups copy the shape of Statamic's (`statamic.cp` with SubstituteBindings in
 * the middle, `statamic.cp.authenticated` after it), with middleware that write their names into a log.
 */
function fakeControlPanel(): void
{
    app('router')->middlewareGroup('statamic.cp', [
        RecordsOrder::class.':cp-before',
        SubstituteBindings::class,
        RecordsOrder::class.':cp-after',
    ]);
    app('router')->middlewareGroup('statamic.cp.authenticated', [
        RecordsOrder::class.':login-check',
        RecordsOrder::class.':nav',
    ]);
}

it('in the control panel runs what it skips before it shows the screen, once each, in the order of the groups', function () {
    fakeControlPanel();
    Route::middleware(['statamic.cp', 'statamic.cp.authenticated', GuardSetup::using(SetupStub::class, 'Page', 'ghosts_never_migrated')])
        ->get('/cp/ghost/{ghost}', fn (Ghost $ghost) => 'unreachable');

    $this->get('/cp/ghost/1')->assertOk()->assertSee('setup required: Page', false);

    expect(RecordsOrder::$log)->toBe(['cp-before', 'cp-after', 'login-check', 'nav', 'guard']);
});

it('replays nothing for a route that does not carry the control panel groups, even where the groups exist', function () {
    fakeControlPanel();
    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Page', 'ghosts_never_migrated')])
        ->get('/not-cp/ghost/{ghost}', fn (Ghost $ghost) => 'unreachable');

    $this->get('/not-cp/ghost/1')->assertOk()->assertSee('setup required: Page', false);

    expect(RecordsOrder::$log)->toBe(['guard']);
});

it('leaves a migrated install to the normal pipeline: nothing runs twice, the guard is never asked', function () {
    fakeControlPanel();
    Route::middleware(['statamic.cp', 'statamic.cp.authenticated', GuardSetup::using(SetupStub::class, 'Page', 'widgets')])
        ->get('/cp/widget/{widget}', fn (Widget $widget) => 'widget '.$widget->id);

    $widget = Widget::create(['email' => 'cp@example.com']);

    $this->get('/cp/widget/'.$widget->id)->assertOk()->assertSee('widget '.$widget->id);

    expect(RecordsOrder::$log)->toBe(['cp-before', 'cp-after', 'login-check', 'nav'])
        ->and(SetupStub::$calls)->toBe([]);
});

it('does not show the screen or write the log line to somebody the login check refuses', function () {
    fakeControlPanel();
    RecordsOrder::$refuse = ['login-check'];
    Route::middleware(['statamic.cp', 'statamic.cp.authenticated', GuardSetup::using(SetupStub::class, 'Page', 'ghosts_never_migrated')])
        ->get('/cp/ghost/{ghost}', fn (Ghost $ghost) => 'unreachable');

    $this->get('/cp/ghost/1', ['Accept' => 'application/json'])->assertUnauthorized();

    expect(SetupStub::$calls)->toBe([])
        ->and(RecordsOrder::$log)->not->toContain('guard')
        ->and(RecordsOrder::$log)->not->toContain('nav');
});

it('turns a guard that answers with something other than a response into one', function () {
    Route::middleware(['web', GuardSetup::using(SetupStubReturningString::class, 'Page', 'ghosts_never_migrated')])
        ->get('/stringy/{ghost}', fn (Ghost $ghost) => 'unreachable');

    $this->get('/stringy/1')->assertOk()->assertSee('plain text screen');
});

it('keeps a bound model that does not exist a 404 once the screen is not needed', function () {
    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Widgets', 'widgets')])
        ->get('/missing/{widget}', fn (Widget $widget) => 'unreachable');

    $this->get('/missing/424242')->assertNotFound();
});

it('is placed ahead of SubstituteBindings in the router priority list, once', function () {
    // The router's list is filled when the HTTP kernel is built; a request builds it.
    app(Kernel::class);
    $priority = app('router')->middlewarePriority;

    expect(array_keys($priority, GuardSetup::class, true))->toHaveCount(1)
        ->and(array_search(GuardSetup::class, $priority, true))->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
});

it('stays ahead of SubstituteBindings when another package makes the kernel sync its priority list afterwards', function () {
    // Statamic's API provider does exactly this while booting, after brand-context has booted:
    // any group or priority method of the kernel copies the kernel's own list over the router's.
    $kernel = app(Kernel::class);
    $kernel->appendMiddlewareToGroup('web', 'Some\\Other\\Package\\Middleware');
    $kernel->appendToMiddlewarePriority('Some\\Other\\Package\\Middleware');

    $priority = app('router')->middlewarePriority;

    expect(array_keys($priority, GuardSetup::class, true))->toHaveCount(1)
        ->and(array_search(GuardSetup::class, $priority, true))->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
});

it('does not drop a middleware another package pushed into a group when the provider registers again', function () {
    app(Kernel::class);
    app('router')->pushMiddlewareToGroup('web', 'Some\\Other\\Package\\Middleware');

    $register = new ReflectionMethod(ServiceProvider::class, 'registerMiddleware');
    $register->invoke(new ServiceProvider(app()));

    expect(app('router')->getMiddlewareGroups()['web'])->toContain('Some\\Other\\Package\\Middleware')
        ->and(array_keys(app('router')->middlewarePriority, GuardSetup::class, true))->toHaveCount(1);
});

it('warns, and carries on, when the addon guard finds nothing to show although a listed table is missing', function () {
    Log::spy();
    $this->withoutExceptionHandling();
    Route::middleware(['web', GuardSetup::using(SetupStubReturningNull::class, 'Page', 'widgets', 'ghosts_never_migrated')])
        ->get('/disagree/{widget}', fn (Widget $widget) => 'widget '.$widget->id);

    $widget = Widget::create(['email' => 'd@example.com']);

    $this->get('/disagree/'.$widget->id)->assertOk()->assertSee('widget '.$widget->id);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'ghosts_never_migrated'))->once();
});

it('checks a list of tables with one query, and still finds the one that is missing', function () {
    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Page', 'widgets', 'brands', 'brand_user')])
        ->get('/three-present', fn () => 'ok');
    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Page', 'widgets', 'brands', 'ghosts_never_migrated')])
        ->get('/one-missing', fn () => 'unreachable');

    DB::enableQueryLog();
    $this->get('/three-present')->assertOk()->assertSee('ok');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $this->get('/one-missing')->assertOk()->assertSee('setup required: Page [ghosts_never_migrated]', false);

    // One listing query (SQLite adds a probe of its own), not one per table: three tables, fewer than three.
    expect($queries)->toBeLessThan(3)
        ->and(SetupStub::$calls)->toHaveCount(1);
});

it('lets a database that cannot be reached stay an error, not a setup screen', function () {
    $this->withoutExceptionHandling();
    config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => '']]);
    config(['database.default' => 'broken']);

    Route::middleware(['web', GuardSetup::using(SetupStub::class, 'Widgets', 'widgets')])
        ->get('/outage/{widget}', fn (Widget $widget) => 'unreachable');

    $thrown = null;

    try {
        $this->get('/outage/1');
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        // RefreshDatabase rolls its transaction back on the default connection in tearDown.
        config(['database.default' => 'testing']);
    }

    expect($thrown)->not->toBeNull()
        ->and(SetupStub::$calls)->toBe([]);
});
