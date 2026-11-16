<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

/** A setup class whose guard answers with a plain string instead of a response object. */
final class SetupStubReturningString
{
    public static function guard(string $title, string ...$tables): string
    {
        return 'plain text screen';
    }
}
