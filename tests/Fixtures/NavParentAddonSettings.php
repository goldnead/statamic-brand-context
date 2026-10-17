<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

/**
 * An addon with a Control Panel navigation of its own, which says so.
 *
 * Same namespace and permission as {@see OrderedAddonSettings}; on top of it
 * the one optional method that moves its sidebar link from the suite's general
 * page to a "Settings" child under its own entry.
 */
class NavParentAddonSettings extends OrderedAddonSettings
{
    public static function settingsNavParent(): array
    {
        return ['Tools', 'Zeppelin'];
    }
}
