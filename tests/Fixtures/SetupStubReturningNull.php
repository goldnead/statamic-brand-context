<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;

/** A setup class whose guard finds nothing to show, whatever tables it is given. */
final class SetupStubReturningNull
{
    public static function guard(string $title, string ...$tables): ?Response
    {
        return null;
    }
}
