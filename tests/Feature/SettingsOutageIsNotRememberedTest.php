<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Settings\NamespaceSettings;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

/**
 * B4: Ein kurzer DB- oder Cache-Aussetzer ließ `overrides()` ein leeres
 * Ergebnis liefern und es für die Lebensdauer des Objekts behalten. Ein
 * Worker lief danach auf Config-Werten, ohne dass es jemand erfuhr.
 */
function flakySettings(int $brandId, int $failures): NamespaceSettings
{
    return new class('widgets', $brandId, app(SettingsRegistry::class), app(SettingsManager::class), app(ConfigRepository::class), $failures) extends NamespaceSettings
    {
        public int $reads = 0;

        public function __construct(string $ns, ?int $brandId, SettingsRegistry $r, SettingsManager $m, ConfigRepository $c, protected int $failures)
        {
            parent::__construct($ns, $brandId, $r, $m, $c);
        }

        protected function read(): array
        {
            $this->reads++;

            if ($this->reads <= $this->failures) {
                throw new RuntimeException('db gone away');
            }

            return ['label' => 'from-db'];
        }
    };
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(now());
    $this->brand = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
});

it('does not keep an empty result from an outage for the lifetime of the object', function () {
    // Cache path and the fallback read both fail: two reads in one call.
    $settings = flakySettings($this->brand->id, 2);

    expect($settings->overrides())->toBe([]);

    // Inside the pause nothing is asked again.
    expect($settings->overrides())->toBe([])
        ->and($settings->reads)->toBe(2);

    // The database is back and the pause is over: the real overrides.
    Carbon::setTestNow(now()->addSeconds(10));
    expect($settings->overrides())->toBe(['label' => 'from-db']);
});

it('reports the outage instead of swallowing it', function () {
    Exceptions::fake();

    flakySettings($this->brand->id, 2)->overrides();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'db gone away');
});

it('reports an outage once per object, not on every read', function () {
    Exceptions::fake();

    $settings = flakySettings($this->brand->id, 99);
    $settings->overrides();
    Carbon::setTestNow(now()->addSeconds(10));
    $settings->overrides();
    Carbon::setTestNow(now()->addSeconds(10));
    $settings->overrides();

    Exceptions::assertReportedCount(1);
});

it('still remembers a successful read', function () {
    $settings = flakySettings($this->brand->id, 0);

    $settings->overrides();
    $settings->overrides();

    expect($settings->reads)->toBe(1);
});

afterEach(fn () => Carbon::setTestNow());
