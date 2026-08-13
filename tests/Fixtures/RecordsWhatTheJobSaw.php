<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job that writes down what the brand scope let it see.
 *
 * Static properties rather than a return value or the database: the point of
 * the test is what the job could read WHILE it ran, in a process that is not
 * the one that pushed it, and the row count it saw is the only honest measure
 * of that.
 */
class RecordsWhatTheJobSaw implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public static bool $ran = false;

    public static ?int $brandId = null;

    public static int $widgetsVisible = 0;

    public static function reset(): void
    {
        static::$ran = false;
        static::$brandId = null;
        static::$widgetsVisible = 0;
    }

    public function handle(): void
    {
        $brands = app('brand-context');

        static::$ran = true;
        static::$brandId = $brands->hasCurrent() ? $brands->currentId() : null;
        static::$widgetsVisible = Widget::query()->count();
    }
}
