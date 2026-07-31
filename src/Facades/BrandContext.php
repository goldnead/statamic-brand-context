<?php

namespace Goldnead\BrandContext\Facades;

use Goldnead\BrandContext\BrandManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool multiBrandEnabled()
 * @method static \Goldnead\BrandContext\Models\Brand default()
 * @method static int defaultId()
 * @method static \Goldnead\BrandContext\Models\Brand current()
 * @method static int currentId()
 * @method static bool hasCurrent()
 * @method static \Goldnead\BrandContext\BrandManager setCurrent(\Goldnead\BrandContext\Models\Brand|int|string|null $brand)
 * @method static \Goldnead\BrandContext\BrandManager forget()
 * @method static mixed runFor(\Goldnead\BrandContext\Models\Brand|int|string $brand, \Closure $callback)
 * @method static mixed withoutBrandScope(\Closure $callback)
 * @method static bool scopeIsDisabled()
 * @method static string failMode()
 *
 * @see BrandManager
 */
class BrandContext extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'brand-context';
    }
}
