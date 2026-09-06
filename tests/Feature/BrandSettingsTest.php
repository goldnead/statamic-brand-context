<?php

use Goldnead\BrandContext\Http\Controllers\Cp\BrandSettingsController;
use Goldnead\BrandContext\Http\Requests\UpdateBrandSettingsRequest;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\BrandContext\Tests\Fixtures\FakeAddonSettings;
use Goldnead\BrandContext\Tests\Fixtures\FakeUser;
use Goldnead\BrandContext\Tests\Fixtures\LateAddonSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

/**
 * The settings layer.
 *
 * The properties asserted here are the ones the three per-addon versions each
 * promised separately, plus the one none of them could: that two brands do not
 * share a value, and that switching brand actually moves the live config.
 */
beforeEach(function () {
    // The packaged config, as a host's `config/widgets.php` would have it.
    // Captured as the baseline the first time the manager applies, so it has to
    // be in place before anything else happens.
    config()->set('widgets', [
        'label' => 'packaged',
        'retention' => ['days' => 30],
        'enabled' => true,
        'redact_keys' => ['authorization'],
        'retry_on_status' => [500, 502, 504],
        'strategy' => 'exponential',
    ]);

    $registry = app(SettingsRegistry::class);
    $registry->flush();
    $registry->register(FakeAddonSettings::class);

    $this->settings = app(SettingsManager::class);
});

it('follows the config file when nothing is stored', function () {
    $this->settings->apply(force: true);

    expect(config('widgets.retention.days'))->toBe(30)
        ->and($this->settings->for('widgets')->get('retention.days'))->toBe(30);

    expect(BrandSetting::query()->count())->toBe(0);
});

it('puts a stored override onto the live config', function () {
    $this->settings->for('widgets')->save(['retention.days' => 7]);

    expect(config('widgets.retention.days'))->toBe(7);

    // And it survives a fresh apply, which is what every other process does.
    $this->settings->apply(force: true);

    expect(config('widgets.retention.days'))->toBe(7);
});

it('deletes the row instead of pinning a value equal to the packaged default', function () {
    $scope = $this->settings->for('widgets');

    $scope->save(['retention.days' => 7]);
    expect(BrandSetting::query()->where('key', 'retention.days')->count())->toBe(1);

    $scope->save(['retention.days' => 30]);

    expect(BrandSetting::query()->where('key', 'retention.days')->count())->toBe(0)
        ->and(config('widgets.retention.days'))->toBe(30);
});

it('keeps false, zero and the empty list apart from unset', function () {
    $scope = $this->settings->for('widgets');

    $scope->save(['enabled' => false, 'redact_keys' => []]);

    expect(config('widgets.enabled'))->toBeFalse()
        ->and(config('widgets.redact_keys'))->toBe([])
        ->and(BrandSetting::query()->count())->toBe(2);

    // Null is a real value for a nullable field, and storing it must not be
    // read back as "no override".
    $scope->save(['retention.days' => null]);

    expect(config('widgets.retention.days'))->toBeNull()
        ->and(BrandSetting::query()->where('key', 'retention.days')->count())->toBe(1);
});

it('types a value on the way in, whichever write path it came from', function () {
    // Straight to the store, past the Control Panel controller — a console
    // command, a data migration, a call through the BrandSettings facade. The
    // coercion used to live in the controller alone, so every one of these
    // paths could store a string where an integer belongs. A "30" there never
    // equals the packaged 30, so the row could never be deleted, and every
    // reader of config() got a string where the code expects a number.
    $scope = $this->settings->for('widgets');

    $scope->save(['retention.days' => '7', 'enabled' => '0', 'label' => 123]);

    expect(config('widgets.retention.days'))->toBe(7)
        ->and(config('widgets.enabled'))->toBeFalse()
        ->and(config('widgets.label'))->toBe('123');

    // And the typed value is now comparable to the packaged default, so
    // "back to default" reaches the delete branch.
    $scope->save(['retention.days' => '30']);

    expect(BrandSetting::query()->where('key', 'retention.days')->count())->toBe(0);
});

it('lets a list of integers return to its packaged default', function () {
    // A textarea hands back strings. Stored as `["500","502","504"]` they
    // never equal a packaged `[500, 502, 504]`, so the row could never be
    // deleted: the field stayed pinned forever, a later release could not move
    // it, and a reader doing in_array($code, …, true) silently stopped
    // matching. Found in webhook-manager's retry status list, 06.09.2026.
    $scope = $this->settings->for('widgets');

    $scope->save(['retry_on_status' => ['500', '502', '504']]);

    expect(config('widgets.retry_on_status'))->toBe([500, 502, 504])
        ->and(BrandSetting::query()->where('key', 'retry_on_status')->count())->toBe(0);
});

it('refuses a select value that is not one of the offered options', function () {
    $user = new FakeUser('admin', 'admin@example.com', null, ['manage widget settings']);

    $ok = settingsRequest(['namespace' => 'widgets', 'settings' => ['strategy' => 'fixed']], $user);
    $bad = settingsRequest(['namespace' => 'widgets', 'settings' => ['strategy' => 'sha257']], $user);

    // Offered as a plain string this field renders as a free-text box and the
    // first typo reaches the code that consumes it. The rule closes the set.
    expect(validatorFor($ok)->passes())->toBeTrue()
        ->and(validatorFor($bad)->passes())->toBeFalse();
});

it('reads select options written either way round', function () {
    // The map spelling, as the contract documents it.
    expect(SettingsRegistry::normaliseOptions(['new' => 'Neu', 'won' => 'Gewonnen']))
        ->toBe([
            ['value' => 'new', 'label' => 'Neu'],
            ['value' => 'won', 'label' => 'Gewonnen'],
        ]);

    // The list spelling, which is what Statamic's own Select takes and what
    // leadhub and webhook-manager wrote on the first day this existed. Read as
    // a map it would offer the allowed values as [0, 1] and the validation
    // would refuse every real answer — nobody could save the field at all,
    // and the message would blame the value rather than the misread contract.
    expect(SettingsRegistry::normaliseOptions([
        ['value' => 'new', 'label' => 'Neu'],
        ['value' => 'won'],
    ]))->toBe([
        ['value' => 'new', 'label' => 'Neu'],
        ['value' => 'won', 'label' => 'won'],
    ]);
});

it('ignores a key the addon does not offer', function () {
    $this->settings->for('widgets')->save(['secret.token' => 'nope']);

    expect(BrandSetting::query()->count())->toBe(0)
        ->and(config('widgets.secret.token'))->toBeNull();
});

it('does not let a row left over from an older release reach the config', function () {
    // A field that existed in a previous version of the addon and no longer
    // does. The row survives an upgrade; the value must not.
    BrandSetting::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'namespace' => 'widgets',
        'key' => 'removed_in_v2',
        'value' => 'ghost',
    ]);

    $this->settings->forget('widgets');
    $this->settings->apply(force: true);

    expect(config('widgets.removed_in_v2'))->toBeNull();
});

it('keeps two brands apart and moves the config when the brand changes', function () {
    $this->enableMultiBrand();

    $default = Brand::default();
    $other = Brand::query()->create(['handle' => 'second', 'name' => 'Second']);

    $manager = app('brand-context');
    $settings = $this->settings;

    // Without the listener the manager never hears about a switch, so wire it
    // the way the service provider does at boot.
    $manager->onBrandChanged(fn () => $settings->brandChanged());

    $manager->setCurrent($default);
    $settings->apply(force: true);
    $settings->for('widgets')->save(['label' => 'first brand']);

    $manager->setCurrent($other);

    // The other brand has stored nothing, so it sees the packaged value — not
    // the first brand's. This is the assertion the per-addon tables could not
    // make at all: they have no brand column, so both brands read one row.
    expect(config('widgets.label'))->toBe('packaged');

    $settings->for('widgets')->save(['label' => 'second brand']);
    expect(config('widgets.label'))->toBe('second brand');

    $manager->setCurrent($default);
    expect(config('widgets.label'))->toBe('first brand');

    expect(BrandSetting::query()->acrossBrands()->count())->toBe(2);
});

it('restores the previous brand config after runFor', function () {
    $this->enableMultiBrand();

    $default = Brand::default();
    $other = Brand::query()->create(['handle' => 'second', 'name' => 'Second']);

    $manager = app('brand-context');
    $settings = $this->settings;
    $manager->onBrandChanged(fn () => $settings->brandChanged());

    $manager->setCurrent($default);
    $settings->apply(force: true);
    $settings->for('widgets')->save(['label' => 'first brand']);

    $manager->runFor($other, function () use ($settings) {
        $settings->for('widgets')->save(['label' => 'second brand']);
        expect(config('widgets.label'))->toBe('second brand');
    });

    // The whole point: what runs after the callback must not still be holding
    // the callback's brand.
    expect(config('widgets.label'))->toBe('first brand');
});

it('applies an addon that registered after the first apply', function () {
    // The cheap exit in apply() used to key on the brand alone. An addon that
    // registered late — a `bootAddon()` that runs after `booted`, an addon
    // enabled at runtime, an Octane worker on a container that booted
    // differently — then never had its overrides pushed: the row is there, the
    // save said it worked, and config() answers with the packaged default for
    // the rest of the process. On a single-brand install nothing ever changes
    // brand, so nothing ever corrects it.
    $this->settings->apply();

    config()->set('latecomer', ['flag' => false]);

    $registry = app(SettingsRegistry::class);
    $registry->register(LateAddonSettings::class);

    BrandSetting::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'namespace' => 'latecomer',
        'key' => 'flag',
        'value' => true,
    ]);

    $this->settings->apply();

    expect(config('latecomer.flag'))->toBeTrue();
});

it('does not poison the cache when multi-brand runs without a resolved brand', function () {
    // A console command that never set a brand. The manager used to ask
    // currentId(), which falls back to the default brand — while BrandScope
    // asks hasCurrent(), which is false, and fails closed. So the manager
    // believed it was serving brand 1, got no rows, and wrote that empty
    // result into the cache under brand 1's key, forever. The next request
    // that really had brand 1 current then ran on packaged defaults.
    $this->enableMultiBrand();

    $default = Brand::default();
    $manager = app('brand-context');
    $settings = $this->settings;
    $manager->onBrandChanged(fn () => $settings->brandChanged());

    $manager->setCurrent($default);
    $settings->apply(force: true);
    $settings->for('widgets')->save(['label' => 'stored']);

    // Now drop the brand, the way a console process has it.
    $manager->forget();

    expect($manager->hasCurrent())->toBeFalse()
        ->and(config('widgets.label'))->toBe('packaged');

    // And the brand's own settings are still intact when it comes back.
    $manager->setCurrent($default);

    expect(config('widgets.label'))->toBe('stored');
});

it('leaves a runtime config change alone when the brand switches', function () {
    // The first version reset the whole config root on every apply, which also
    // threw away anything the host had set at runtime: a feature flag, a
    // middleware, a feature-flag package. Silently, and on every iteration of
    // a RunsForEachBrand loop inside a queue worker. Measured in
    // statamic-leadhub on 06.09.2026, where it turned 20 of that addon's own
    // tests red for no reason of its own.
    $this->enableMultiBrand();

    $default = Brand::default();
    $other = Brand::query()->create(['handle' => 'second', 'name' => 'Second']);

    $manager = app('brand-context');
    $settings = $this->settings;
    $manager->onBrandChanged(fn () => $settings->brandChanged());

    $manager->setCurrent($default);
    $settings->apply(force: true);

    // Not a setting this layer offers — nobody's business but the host's.
    config()->set('widgets.runtime_flag', true);

    $manager->setCurrent($other);
    expect(config('widgets.runtime_flag'))->toBeTrue();

    $manager->setCurrent($default);
    expect(config('widgets.runtime_flag'))->toBeTrue();
});

it('takes back its own override when the brand no longer has one', function () {
    // The other half of the same change: undoing key by key must still undo.
    $this->enableMultiBrand();

    $default = Brand::default();
    $other = Brand::query()->create(['handle' => 'second', 'name' => 'Second']);

    $manager = app('brand-context');
    $settings = $this->settings;
    $manager->onBrandChanged(fn () => $settings->brandChanged());

    $manager->setCurrent($default);
    $settings->apply(force: true);
    $settings->for('widgets')->save(['label' => 'first brand']);

    $manager->setCurrent($other);

    expect(config('widgets.label'))->toBe('packaged');
});

it('still draws the screen on an install whose migrations never ran', function () {
    // The likeliest first impression on a standalone install of one addon:
    // installed from the Marketplace, `php artisan migrate` not run yet.
    // `BrandManager::default()` throws there — correctly, it is the honest
    // answer to "which brand is this" — but the settings screen must not pass
    // that on as a 500. Reading needs no brand at all.
    Schema::dropIfExists('brand_settings');
    Brand::query()->delete();
    app('brand-context')->forget();

    $controller = app(BrandSettingsController::class);

    // As an Inertia visit, so the response is JSON. Rendering the first visit
    // would need the application's root Blade view, which the testbench
    // skeleton has no reason to ship.
    $request = Request::create('/cp/brand-settings', 'GET', server: ['HTTP_X_INERTIA' => 'true']);
    $request->setUserResolver(fn () => new FakeUser('admin', 'admin@example.com', null, ['manage widget settings']));

    $props = $controller->index($request)->toResponse($request)->getData(true)['props'];

    expect($props['brand'])->toBeNull()
        ->and($props['writable'])->toBeFalse()
        ->and($props['multiBrand'])->toBeFalse()
        // And it still shows what the config files say, so the page is worth
        // opening rather than merely not broken.
        ->and($props['sections'][0]['values']['label'])->toBe('packaged');
});

it('refuses two addons claiming the same namespace', function () {
    $registry = app(SettingsRegistry::class);

    $other = new class extends FakeAddonSettings {};

    expect(fn () => $registry->register($other::class))
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

it('refuses a class that does not implement the contract', function () {
    expect(fn () => app(SettingsRegistry::class)->register(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Authorisation, at the request boundary rather than at the model.
 *
 * Called directly with a user resolver rather than over HTTP, which is how the
 * membership screen next door is tested: the Statamic Control Panel routes do
 * not boot in this testbench, and a test that skipped the check to get a
 * response would be asserting nothing about the thing it names.
 */
function settingsRequest(array $payload, ?FakeUser $user): UpdateBrandSettingsRequest
{
    $request = UpdateBrandSettingsRequest::create('/cp/brand-settings', 'PATCH', $payload);
    $request->setContainer(app());
    $request->setUserResolver(fn () => $user);

    return $request;
}

/**
 * A validator over only the keys a payload actually carries.
 *
 * The real rules require every field to be `present`, because the screen posts
 * a whole section at a time. A test about one field's rule would otherwise
 * fail on the absence of the other five and prove nothing about the rule it
 * names.
 */
function validatorFor(UpdateBrandSettingsRequest $request): Validator
{
    $payload = $request->all();
    $rules = collect($request->rules())
        ->filter(fn ($rule, $key) => Arr::has($payload, str_replace('\\.', '.', $key)))
        ->all();

    return validator($payload, $rules);
}

it('lets somebody with the addon permission write that addon', function () {
    $user = new FakeUser('admin', 'admin@example.com', null, ['manage widget settings']);

    expect(settingsRequest(['namespace' => 'widgets', 'settings' => ['label' => 'x']], $user)->authorize())
        ->toBeTrue();
});

it('refuses a namespace the user has no permission for', function () {
    // Holding one addon's permission must not open another's. This is the
    // whole reason the request takes one namespace and checks the permission
    // that namespace declared, rather than a single page-level right.
    $user = new FakeUser('half', 'half@example.com', null, ['manage something else settings']);

    expect(settingsRequest(['namespace' => 'widgets', 'settings' => ['label' => 'x']], $user)->authorize())
        ->toBeFalse();
});

it('refuses a namespace nobody registered, rather than validating it', function () {
    // A payload naming an unregistered namespace has no permission attached to
    // it. Falling through to the rules would answer "the settings field is
    // required", which reads as a form bug instead of the refusal it is.
    $user = new FakeUser('admin', 'admin@example.com', null, ['manage widget settings']);

    expect(settingsRequest(['namespace' => 'invented', 'settings' => ['label' => 'x']], $user)->authorize())
        ->toBeFalse();
});

it('refuses when nobody is signed in', function () {
    expect(settingsRequest(['namespace' => 'widgets', 'settings' => ['label' => 'x']], null)->authorize())
        ->toBeFalse();
});

it('reports the permission the addon declared, not one derived from the namespace', function () {
    // `manage widget settings`, singular — the addons that shipped a screen
    // before this layer use names that a derived `manage widgets settings`
    // would silently stop matching.
    expect(app(SettingsRegistry::class)->permission('widgets'))->toBe('manage widget settings');
});
