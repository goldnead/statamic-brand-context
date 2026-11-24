<?php

namespace Goldnead\BrandContext\Exceptions;

use RuntimeException;

/**
 * A branded record was created, but no brand is current and none was named.
 *
 * Reads are fail closed in that situation (the scope shows nothing), so a write
 * that quietly fell back to the default brand would put the row where nobody
 * looking at the right brand can ever see it. Single-brand installations never
 * see this: they always have the default brand as their current one.
 */
class NoCurrentBrand extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "Cannot create [{$model}]: multi-brand is on, no brand is current and no brand id was set. ".
            'Run inside BrandContext::runFor() / the RunsForEachBrand trait, or set the brand id explicitly.'
        );
    }
}
