<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A job that writes down the stored setting it could read while it ran.
 *
 * Reads the live config, as every addon job does. The value is only the stored
 * one if the settings layer was re-applied for the job's brand.
 */
class RecordsWhatConfigTheJobSaw implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public static bool $ran = false;

    public static mixed $retentionDays = null;

    public static function reset(): void
    {
        static::$ran = false;
        static::$retentionDays = null;
    }

    public function handle(): void
    {
        static::$ran = true;
        static::$retentionDays = config('widgets.retention.days');
    }
}
