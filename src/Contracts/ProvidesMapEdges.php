<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Map\MapEdge;

/**
 * Optional second half of {@see ProvidesMapObjects}: the connections an addon
 * can read from its own data.
 *
 * Implement it on the same class. An addon lists only the edges it itself
 * knows (automations: waits for, starts, sends a template; offers: which
 * products; courses: which product). The map puts them together, so no addon
 * has to know any other. Never guess: an edge exists because a field says so.
 */
interface ProvidesMapEdges
{
    /**
     * Every edge this addon can derive right now.
     *
     * @return iterable<MapEdge>
     */
    public function edges(): iterable;
}
