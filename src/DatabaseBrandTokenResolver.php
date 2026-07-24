<?php

namespace Goldnead\BrandContext;

use Goldnead\BrandContext\Contracts\BrandTokenResolver;
use Goldnead\BrandContext\Models\Brand;

/**
 * Default resolver: matches a bearer token against the sha256 hash stored per
 * brand in `brands.settings.api_token_hash`. Constant-time, fail-closed.
 *
 * The hub can bind its own implementation to the BrandTokenResolver contract.
 */
class DatabaseBrandTokenResolver implements BrandTokenResolver
{
    public function resolve(string $token): ?Brand
    {
        if ($token === '') {
            return null;
        }

        $candidate = hash('sha256', $token);
        $match = null;

        // Iterate all brands and compare every hash to keep timing uniform.
        foreach (Brand::query()->get() as $brand) {
            $hash = data_get($brand->settings, 'api_token_hash');

            if (is_string($hash) && hash_equals($hash, $candidate)) {
                $match = $brand;
            }
        }

        return $match;
    }
}
