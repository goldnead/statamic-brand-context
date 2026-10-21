<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Simulation\SimEvent;

/**
 * Optional: where a path can begin at this object.
 *
 * Every dry run needs a start. A lead magnet begins when somebody asks for it, a product when
 * somebody buys it, an automation with no map connection pointing at it when its own trigger
 * fires. The provider names those events; the planner starts one path per event at every object
 * that nothing on the map points at. An object with no entry (a newsletter card, a box) is only
 * ever reached over a connection.
 *
 * Found with `method_exists`, like the other optional halves.
 */
interface ProvidesMapPaths
{
    /** @return list<SimEvent> */
    public function paths(string $id): array;
}
