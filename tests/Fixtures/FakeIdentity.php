<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

/**
 * The shape of `Goldnead\IdentityContracts\Identity` that this package reads.
 *
 * Copied rather than depended on: identity-contracts is not a requirement of
 * brand-context, but the two are installed side by side in every real host, and
 * an `Identity` is what a consuming addon has in hand when it wants to know
 * whether the actor belongs to a brand.
 */
class FakeIdentity
{
    public function __construct(
        public string $type = 'user',
        public ?string $id = null,
        public ?string $userId = null,
    ) {}
}
