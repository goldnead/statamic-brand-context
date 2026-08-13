<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Queue\BrandOnQueue;
use Goldnead\BrandContext\Tests\Fixtures\RecordsWhatTheJobSaw;
use Goldnead\BrandContext\Tests\Fixtures\Widget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    RecordsWhatTheJobSaw::reset();

    // The database driver, not `sync`: a job that runs inline in the process
    // that dispatched it inherits that process's brand no matter what this
    // class does, so it could never show the defect or the fix. The worker is
    // simulated by forgetting the brand between push and run, which is exactly
    // what a process with no request behind it looks like.
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

    $this->brandA = Brand::factory()->create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::factory()->create(['handle' => 'brand-b', 'name' => 'Brand B']);

    BrandContext::runFor($this->brandA, fn () => Widget::create(['email' => 'a@example.com']));
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b1@example.com']));
    BrandContext::runFor($this->brandB, fn () => Widget::create(['email' => 'b2@example.com']));
});

afterEach(function () {
    Schema::dropIfExists('jobs');
});

function laufenLassen(): void
{
    BrandContext::forget();

    test()->artisan('queue:work', ['--once' => true, '--tries' => 1])->run();
}

it('carries the brand of the dispatcher into the worker', function () {
    BrandContext::setCurrent($this->brandB);
    RecordsWhatTheJobSaw::dispatch();

    laufenLassen();

    expect(RecordsWhatTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatTheJobSaw::$brandId)->toBe($this->brandB->id)
        ->and(RecordsWhatTheJobSaw::$widgetsVisible)->toBe(2);
});

/**
 * The defect this class was written for, kept as a test rather than as a
 * memory: without the payload key the job runs, sees nothing, and reports
 * success. No exception, no failed job, no log line.
 */
it('would see nothing without the brand — the failure this prevents', function () {
    BrandContext::setCurrent($this->brandB);
    RecordsWhatTheJobSaw::dispatch();

    // Strip the key back out of the queued payload, i.e. the state of the
    // world before this class existed.
    $row = DB::table('jobs')->first();
    $payload = json_decode($row->payload, true);
    unset($payload[BrandOnQueue::PAYLOAD_KEY]);
    DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload)]);

    laufenLassen();

    expect(RecordsWhatTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatTheJobSaw::$brandId)->toBeNull()
        ->and(RecordsWhatTheJobSaw::$widgetsVisible)->toBe(0);
});

it('does not widen a brandless job to the default brand', function () {
    BrandContext::forget();
    RecordsWhatTheJobSaw::dispatch();

    laufenLassen();

    expect(RecordsWhatTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatTheJobSaw::$brandId)->toBeNull()
        ->and(RecordsWhatTheJobSaw::$widgetsVisible)->toBe(0);
});

it('runs with no brand when the brand was deleted between push and run', function () {
    BrandContext::setCurrent($this->brandB);
    RecordsWhatTheJobSaw::dispatch();

    BrandContext::forget();
    Brand::query()->whereKey($this->brandB->id)->delete();

    laufenLassen();

    expect(RecordsWhatTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatTheJobSaw::$brandId)->toBeNull();
});

/**
 * On `sync` the job runs inside the request that dispatched it. Forgetting the
 * brand when it ends would take it away from the rest of that request — the
 * reason this keeps a stack instead of calling forget().
 *
 * It also stands in for something no single test can state directly: this is
 * the LAST test in the file, so it runs against the fifth application built in
 * this process. Laravel empties the payload callbacks between tests, so a
 * version of the hook that registered itself only once would leave this test
 * with no brand at all — and it did, before that guard was removed.
 */
it('leaves the dispatching process its own brand', function () {
    config()->set('queue.default', 'sync');

    BrandContext::setCurrent($this->brandA);
    RecordsWhatTheJobSaw::dispatch();

    expect(RecordsWhatTheJobSaw::$ran)->toBeTrue()
        ->and(RecordsWhatTheJobSaw::$brandId)->toBe($this->brandA->id)
        ->and(BrandContext::hasCurrent())->toBeTrue()
        ->and(BrandContext::currentId())->toBe($this->brandA->id);
});
