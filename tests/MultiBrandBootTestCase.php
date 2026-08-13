<?php

namespace Goldnead\BrandContext\Tests;

use Illuminate\Routing\Middleware\SubstituteBindings;

/**
 * A test case whose multi-brand mode is on BEFORE the service provider boots.
 *
 * `TestCase::enableMultiBrand()` flips the config afterwards, which is right
 * for everything that reads it per call — the scope, the manager, the
 * middleware. It is not enough for anything the provider decides once at boot
 * and never revisits: the queue hook is registered only under multi-brand, so a
 * test that turned the flag on in `beforeEach` would be testing an application
 * in which it was never wired at all, and would pass for the wrong reason.
 */
abstract class MultiBrandBootTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('brand-context.multi_brand', true);

        // Under multi-brand the provider wires the CP brand switcher into
        // Statamic's own middleware group, and refuses loudly when that group
        // has no SubstituteBindings to sit in front of. A bare Testbench has no
        // such group at all, so it is declared here — the alternative would be
        // to soften a guard that exists to catch a real ordering bug.
        $app['router']->middlewareGroup('statamic.cp', [SubstituteBindings::class]);
    }
}
