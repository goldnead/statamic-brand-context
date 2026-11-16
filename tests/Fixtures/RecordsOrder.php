<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Stands in for a middleware of Statamic's `statamic.cp` and `statamic.cp.authenticated`
 * groups: it writes its name into a log, and can refuse the request the way `Authorize` does.
 */
final class RecordsOrder
{
    /** @var list<string> */
    public static array $log = [];

    /** Names of the middleware that refuse with an AuthenticationException. */
    public static array $refuse = [];

    public function handle(Request $request, Closure $next, string $name)
    {
        self::$log[] = $name;

        if (in_array($name, self::$refuse, true)) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $next($request);
    }
}
