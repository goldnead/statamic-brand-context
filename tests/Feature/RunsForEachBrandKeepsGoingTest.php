<?php

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

/**
 * B6: Der erste Nicht-Null-Exit beendete die Schleife. Die Marken dahinter
 * liefen nie, und nirgends stand, dass sie fehlten.
 */
class FailingProbeCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'probe:failing {--brand=}';

    public static array $seen = [];

    /** @var array<string, string> handle => 'exit' | 'throw' | 'ok' */
    public static array $behaviour = [];

    public function handle(): int
    {
        return $this->forEachBrand(function (): int {
            $handle = BrandContext::current()->handle;
            self::$seen[] = $handle;

            return match (self::$behaviour[$handle] ?? 'ok') {
                'exit' => self::FAILURE,
                'throw' => throw new RuntimeException("{$handle} exploded"),
                default => self::SUCCESS,
            };
        });
    }
}

beforeEach(function (): void {
    FailingProbeCommand::$seen = [];
    FailingProbeCommand::$behaviour = [];
    $this->app[Kernel::class]->registerCommand(new FailingProbeCommand);
    $this->enableMultiBrand();
    Brand::create(['handle' => 'brand-a', 'name' => 'A']);
    Brand::create(['handle' => 'brand-b', 'name' => 'B']);
    Brand::create(['handle' => 'brand-c', 'name' => 'C']);
    BrandContext::forget();
});

it('still runs the brands behind one that returned a failure', function (): void {
    FailingProbeCommand::$behaviour = ['brand-a' => 'exit'];

    $this->artisan('probe:failing')->assertFailed();

    expect(FailingProbeCommand::$seen)->toBe(['default', 'brand-a', 'brand-b', 'brand-c']);
});

it('still runs the brands behind one that threw, and reports it', function (): void {
    FailingProbeCommand::$behaviour = ['brand-b' => 'throw'];

    $this->artisan('probe:failing')->assertFailed();

    expect(FailingProbeCommand::$seen)->toBe(['default', 'brand-a', 'brand-b', 'brand-c']);
});

it('says how many brands ran fine and which did not', function (): void {
    FailingProbeCommand::$behaviour = ['brand-a' => 'exit', 'brand-c' => 'throw'];

    $this->artisan('probe:failing')
        ->expectsOutputToContain('2 of 4 brands ran without error')
        ->expectsOutputToContain('brand-a')
        ->expectsOutputToContain('brand-c')
        ->assertFailed();
});

it('exits cleanly when every brand ran fine', function (): void {
    $this->artisan('probe:failing')->assertSuccessful();

    expect(FailingProbeCommand::$seen)->toBe(['default', 'brand-a', 'brand-b', 'brand-c']);
});

it('finds a brand by numeric id as well as by handle', function (): void {
    $id = Brand::query()->where('handle', 'brand-b')->value('id');

    $this->artisan('probe:failing', ['--brand' => (string) $id])->assertSuccessful();

    expect(FailingProbeCommand::$seen)->toBe(['brand-b']);
});
