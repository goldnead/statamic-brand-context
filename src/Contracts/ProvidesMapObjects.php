<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;

/**
 * What an addon implements so a map (the funnels map view) can point at its
 * objects: a lead magnet, an automation, a course, an offer.
 *
 * The map holds only `type` and `id`. Everything it shows, the label, the
 * Control Panel link, whether the thing is on, a number, it asks the owning
 * addon for, every time. The logic stays where the data is.
 *
 * Register in `boot()` through {@see MapObjectRegistry}.
 * The provider is resolved from the container, so it may take dependencies.
 *
 * **A metric is optional.** An addon without a number returns null and the
 * card is shown without one. Never invent a figure to fill the slot.
 */
interface ProvidesMapObjects
{
    /** Stable key stored on every reference, e.g. `lead_magnet`. Never rename it. */
    public function type(): string;

    /** Human name of the kind, e.g. "Lead magnet". Shown on the picker and the card. */
    public function typeLabel(): string;

    /**
     * Objects to pick from, optionally narrowed by a search string.
     *
     * @return array<int, MapObject>
     */
    public function objects(?string $query = null, int $limit = 50): array;

    /** One object by id, or null when it no longer exists. */
    public function find(string $id): ?MapObject;

    /** The card's single number for this object over `$period`, or null. */
    public function metric(string $id, MapPeriod $period): ?MapMetric;
}
