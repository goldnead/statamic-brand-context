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
     * @param  Closure(Brand|null): int|null  $callback
     */
    protected function forEachBrand(Closure $callback): int
    {
        if (! BrandContext::multiBrandEnabled()) {
            return (int) ($callback(null) ?? self::SUCCESS);
        }

        foreach ($this->brandsToProcess() as $brand) {
            if ($this->shouldAnnounceBrand()) {
                $this->line("Brand: {$brand->handle}");
            }

            $exit = BrandContext::runFor($brand, fn () => $callback($brand));

            if ((int) ($exit ?? self::SUCCESS) !== self::SUCCESS) {
                return (int) $exit;
            }
        }

        return self::SUCCESS;
    }

    /** @return iterable<Brand> */
    protected function brandsToProcess(): iterable
    {
        $only = $this->hasOption('brand') ? $this->option('brand') : null;

        if ($only) {
            return [Brand::query()->where('handle', $only)->orWhere('id', $only)->firstOrFail()];
        }

        return Brand::query()->orderBy('id')->get();
    }

    /** Overridable so a quiet command can stay quiet. */
    protected function shouldAnnounceBrand(): bool
    {
        return true;
    }
}
