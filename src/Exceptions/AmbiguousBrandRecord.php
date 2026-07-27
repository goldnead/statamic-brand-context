<?php

namespace Goldnead\BrandContext\Exceptions;

use RuntimeException;

/**
 * More than one record answered a lookup that was promised to be unique.
 *
 * This is thrown rather than swallowed on purpose. The whole safety argument
 * for deriving a brand from a public token rests on the token addressing
 * exactly one record; the moment two records answer, that argument is void and
 * picking either one would mean handing a visitor of brand A a record of brand
 * B. Failing loudly here turns a silent data leak into an obvious bug report.
 */
class AmbiguousBrandRecord extends RuntimeException
{
    public static function for(string $model, string $column): self
    {
        return new self(
            "Brand lookup on [{$model}.{$column}] matched more than one record. "
            .'Deriving a brand from this column is only safe while it carries a '
            .'database-level unique index across all brands. Add one, or resolve '
            .'the brand some other way.'
        );
    }
}
