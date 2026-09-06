<?php

namespace Goldnead\BrandContext\Facades;

use Goldnead\BrandContext\Settings\SettingsManager;
use Illuminate\Support\Facades\Facade;

/**
 * The explicit reader for a brand's settings.
 *
 * ```php
 * BrandSettings::for('invoices')->get('receipt_policy.only_first_of_subscription');
 * BrandSettings::for('invoices')->values();
 * ```
 *
 * Existing code does not have to use it. {@see SettingsManager} pushes the
 * current brand's overrides onto the live config, so `config('invoices.…')`
 * already answers correctly and the dozens of call sites in the suite keep
 * working untouched. This facade is for new code that would rather name where
 * a value comes from than rely on that.
 *
 * @method static \Goldnead\BrandContext\Settings\NamespaceSettings for(string $namespace)
 * @method static void apply(bool $force = false)
 * @method static void brandChanged()
 * @method static void forget(?string $namespace = null)
 * @method static mixed packagedDefault(string $namespace, string $key)
 * @method static \Goldnead\BrandContext\Settings\SettingsRegistry registry()
 *
 * @see SettingsManager
 */
class BrandSettings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'brand-context.settings';
    }
}
