<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Models\Brand;

interface BrandTokenResolver
{
    /**
     * Resolve a bearer token to its brand, or null if the token is unknown.
     * Implementations MUST use a constant-time comparison (hash_equals) and
     * fail closed (return null) on any miss.
     */
    public function resolve(string $token): ?Brand;
}
