<?php

namespace Goldnead\BrandContext\Concerns;

use Closure;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;

/**
 * Lets an artisan command work under multi-brand.
 *
 * A console run has no session, so no brand is current. With multi-brand on,
 * the global scope then fails closed and every query returns nothing — a
 * scheduled command reports "0 processed" and looks perfectly healthy while
 * doing nothing at all. That failure mode is silent, survives indefinitely and
 * has now been found in four separate commands across three addons.
 *
 * Usage:
 *
 *     class RunDueScheduledJobs extends Command
 *     {
 *         use RunsForEachBrand;
 *
 *         protected $signature = 'automations:run-due {--brand= : Restrict to one brand}';
 *
 *         public function handle(): int
 *         {
 *             return $this->forEachBrand(fn () => $this->process());
 *         }
 *     }
 *
 * Single-brand installs are unaffected: the callback runs exactly once, in the
 * ambient context, with no brand switching at all.
 */
trait RunsForEachBrand
{
    /**
     * Runs the callback once per brand and returns the first non-zero exit code.
     *
     * One brand failing (a non-zero exit, or an exception) does not stop the
     * brands behind it: a cron run that gave up at brand 2 of 5 looked like one
     * error while three brands silently never ran. Every brand runs, exceptions
     * are reported and counted as a failure, and a summary says how many of the
     * brands ran without error and which did not.
     *
     * @param  Closure(Brand|null): int|null  $callback
     */
    protected function forEachBrand(Closure $callback): int
    {
        if (! BrandContext::multiBrandEnabled()) {
            return (int) ($callback(null) ?? self::SUCCESS);
        }

        $brands = collect($this->brandsToProcess())->values();
        $firstExit = self::SUCCESS;
        $failed = [];

        foreach ($brands as $brand) {
            if ($this->shouldAnnounceBrand()) {
                $this->line("Brand: {$brand->handle}");
            }

            try {
                $exit = (int) (BrandContext::runFor($brand, fn () => $callback($brand)) ?? self::SUCCESS);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Brand {$brand->handle}: {$e->getMessage()}");
                $exit = self::FAILURE;
            }

            if ($exit !== self::SUCCESS) {
                $failed[] = $brand->handle;
                $firstExit = $firstExit === self::SUCCESS ? $exit : $firstExit;
            }
        }

        if ($failed !== []) {
            $this->error(sprintf(
                '%d of %d brands ran without error. Failed: %s',
                $brands->count() - count($failed),
                $brands->count(),
                implode(', ', $failed),
            ));
        }

        return $firstExit;
    }

    /** @return iterable<Brand> */
    protected function brandsToProcess(): iterable
    {
        $only = $this->hasOption('brand') ? $this->option('brand') : null;

        if ($only) {
            // An id only when the value is one: comparing the integer column
            // with a handle string is a type error on strict drivers (Postgres).
            return [Brand::query()
                ->where(fn ($query) => ctype_digit((string) $only)
                    ? $query->where('id', (int) $only)->orWhere('handle', $only)
                    : $query->where('handle', $only))
                ->firstOrFail()];
        }

        return Brand::query()->orderBy('id')->get();
    }

    /** Overridable so a quiet command can stay quiet. */
    protected function shouldAnnounceBrand(): bool
    {
        return true;
    }
}
