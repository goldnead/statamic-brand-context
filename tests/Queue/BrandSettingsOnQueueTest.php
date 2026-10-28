<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\BrandContext\Tests\Fixtures\FakeAddonSettings;
use Goldnead\BrandContext\Tests\Fixtures\RecordsWhatConfigTheJobSaw;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Do stored settings reach a queue worker?
 *
 * Asked in the ticket backlog-einstellungen-erreichen-die-warteschlange-nicht.
 * Real `queue:work` on the database driver, with the brand forgotten between
 * push and run, so the worker has no request behind it.
 */
beforeEach(function () {
    RecordsWhatConfigTheJobSaw::reset();

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    config()->set('widgets', [
        'label' => 'packaged',
        'withdrawal_text' => null,
        'retention' => ['days' => 30],
        'enabled' => true,
        'redact_keys' => ['authorization'],
        'retry_on_status' => [500, 502, 504],
        'strategy' => 'exponential',
    ]);

    $registry = app(SettingsRegistry::class);
    $registry->flush();
    $registry->register(FakeAddonSettings::class);

    $this->brandA = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::factory()->create(['handle' => 'brand-b', 'name' => 'Brand B']);

    // What the settings page does: one value per brand, saved from a request.
    $settings = app(SettingsManager::class);
    BrandContext::runFor($this->brandA, fn () => $settings->for('widgets')->save(['retention.days' => 7]));
    BrandContext::runFor($this->brandB, fn () => $settings->for('widgets')->save(['retention.days' => 11]));

    // Boot-time state of a worker: apply() ran once with no brand.
    BrandContext::forget();
    $settings->apply(force: true);
});

afterEach(function () {
    Schema::dropIfExists('jobs');
});

function arbeiterLaufenLassen(): void
{
    BrandContext::forget();

    test()->artisan('queue:work', ['--once' => true, '--tries' => 1])->run();
}

it('gives a worker the stored value of the brand the job was pushed under', function () {
    BrandContext::setCurrent($this->brandB);
    RecordsWhatConfigTheJobSaw::dispatch();

    arbeiterLaufenLassen();

    expect(RecordsWhatConfigTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatConfigTheJobSaw::$retentionDays)->toBe(11);
});

it('gives the next job its own brand value, not the one before', function () {
    BrandContext::setCurrent($this->brandA);
    RecordsWhatConfigTheJobSaw::dispatch();
    arbeiterLaufenLassen();
    expect(RecordsWhatConfigTheJobSaw::$retentionDays)->toBe(7);

    BrandContext::setCurrent($this->brandB);
    RecordsWhatConfigTheJobSaw::dispatch();
    arbeiterLaufenLassen();
    expect(RecordsWhatConfigTheJobSaw::$retentionDays)->toBe(11);
});

it('gives a job pushed with no brand the packaged value, never another brand', function () {
    BrandContext::forget();
    RecordsWhatConfigTheJobSaw::dispatch();

    arbeiterLaufenLassen();

    expect(RecordsWhatConfigTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatConfigTheJobSaw::$retentionDays)->toBe(30);
});
