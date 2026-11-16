<?php

use Goldnead\BrandContext\Http\Middleware\GuardSetup;
use Goldnead\BrandContext\Tests\Fixtures\Ghost;
use Goldnead\BrandContext\Tests\Fixtures\SetupStub;
use Goldnead\BrandContext\Tests\Fixtures\Widget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/*
 * A detail route binds its model by type hint, so the query has run before the
 * controller starts. A guard inside the method comes too late; the middleware
 * has to stand in front of SubstituteBindings.
 */
beforeEach(function () {
    SetupStub::$calls = [];

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
