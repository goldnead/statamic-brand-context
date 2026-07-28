<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Contracts\UserSource;
use Illuminate\Support\Collection;

class FakeUserSource implements UserSource
{
    /** @param array<int, FakeUser> $users */
    public function __construct(protected array $users = []) {}

    public function all(): Collection
    {
        return collect($this->users);
    }
}
