<?php

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

/**
 * The failure this trait exists for is silent: a scheduled command that reports
 * success while the fail-closed scope hides every row from it.
 */
class ProbeCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'probe:brands {--brand=}';

    public static array $seen = [];

    public function handle(): int
    {
        return $this->forEachBrand(function (): int {
            self::$seen[] = BrandContext::hasCurrent() ? BrandContext::current()->handle : null;

            return self::SUCCESS;
        });
    }
}

beforeEach(function (): void {
    ProbeCommand::$seen = [];
    $this->app[Kernel::class]->registerCommand(new ProbeCommand);
});

it('runs once in the ambient context when multi-brand is off', function (): void {
    $this->artisan('probe:brands')->assertSuccessful();

    expect(ProbeCommand::$seen)->toHaveCount(1);
});

it('walks every brand when no brand is current — the scheduler case', function (): void {
    $this->enableMultiBrand();
    Brand::create(['handle' => 'brand-a', 'name' => 'A']);
    Brand::create(['handle' => 'brand-b', 'name' => 'B']);
    BrandContext::forget();

    $this->artisan('probe:brands')->assertSuccessful();

    expect(ProbeCommand::$seen)->toContain('brand-a')
        ->and(ProbeCommand::$seen)->toContain('brand-b');
});

it('can be restricted to a single brand', function (): void {
    $this->enableMultiBrand();
    Brand::create(['handle' => 'brand-a', 'name' => 'A']);
    Brand::create(['handle' => 'brand-b', 'name' => 'B']);
    BrandContext::forget();

    $this->artisan('probe:brands', ['--brand' => 'brand-b'])->assertSuccessful();

    expect(ProbeCommand::$seen)->toBe(['brand-b']);
});

it('sets the brand context for the callback, so scoped queries see rows', function (): void {
    $this->enableMultiBrand();
    $a = Brand::create(['handle' => 'brand-a', 'name' => 'A']);
    BrandContext::forget();

    $this->artisan('probe:brands', ['--brand' => 'brand-a'])->assertSuccessful();

    expect(ProbeCommand::$seen)->toBe(['brand-a']);
});
