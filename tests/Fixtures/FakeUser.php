<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

/**
 * Stands in for a Statamic user without booting the CMS.
 *
 * Shaped like the real thing on purpose: `id()` returns a string for both the
 * file driver (a uuid) and the eloquent driver (a numeric key), which is the
 * only property this package relies on.
 */
class FakeUser
{
    public function __construct(
        public string $id,
        public string $email = 'user@example.com',
        public ?string $displayName = null,
        public array $abilities = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): ?string
    {
        return $this->displayName;
    }

    public function can($ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
