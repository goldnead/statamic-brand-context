<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands in for an addon's own `Support\Setup`: the same `guard()` signature,
 * answering with a plain response instead of an Inertia page.
 */
final class SetupStub
{
    /** @var list<array{0: string, 1: list<string>}> */
    public static array $calls = [];

    public static function guard(string $title, string ...$tables): ?Response
    {
        self::$calls[] = [$title, $tables];

        $missing = array_values(array_filter($tables, fn (string $t) => ! Schema::hasTable($t)));

        return $missing === []
            ? null
            : response('setup required: '.$title.' ['.implode(',', $missing).']', 200);
    }
}
