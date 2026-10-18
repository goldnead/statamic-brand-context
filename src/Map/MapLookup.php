<?php

namespace Goldnead\BrandContext\Map;

/**
 * The answer to "what does this reference point at": the object, or the
 * reason there is none.
 *
 * A dead reference and an addon that is not installed look the same to a plain
 * null, and mean opposite things: the first is a broken link to repair, the
 * second a choice the operator made. {@see $status} keeps them apart.
 */
final class MapLookup
{
    public const FOUND = 'found';

    /** The provider answered and has no such object. */
    public const MISSING = 'missing';

    /** No provider for this type, or it failed. Nothing is known about the object. */
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?MapObject $object = null,
    ) {}

    public static function found(MapObject $object): self
    {
        return new self(self::FOUND, $object);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }
}
