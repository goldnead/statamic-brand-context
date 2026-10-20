<?php

namespace Goldnead\IdentityContracts;

// Stand-in for statamic-identity-contracts when it is not installed, so the typed-actor test can run.
if (! class_exists(Identity::class)) {
    final class Identity
    {
        public function __construct(public string $type, public string $id) {}

        public static function user(int|string $id): self
        {
            return new self('user', (string) $id);
        }
    }
}
