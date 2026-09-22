<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Settings\SettingsRegistry;

/**
 * Where an addon's tab sits on the settings screen, and which icon its sidebar
 * entry carries.
 *
 * **Optional, and it has to stay optional.** Twenty-two addons implement
 * {@see ProvidesSettings} today. A PHP interface has no default
 * implementations, so putting `settingsOrder()` next to `settingsNamespace()`
 * would have been a fatal at boot on every one of them the moment this package
 * updated — the Control Panel down, for a tab order. This interface therefore
 * stands on its own and nothing checks for it:
 * {@see SettingsRegistry::order()} and
 * {@see SettingsRegistry::icon()} ask with
 * `method_exists()` and fall back to the defaults below.
 *
 * It exists for the addon author who wants the compiler to check the
 * signatures, and as the written-down shape of what the registry asks for.
 * Declaring one of the two methods without declaring this interface works
 * exactly as well.
 */
interface DescribesSettingsScreen
{
    /**
     * Where this addon's tab sits. Low sorts first.
     *
     * Everything that says nothing gets {@see
     * \Goldnead\BrandContext\Settings\SettingsRegistry::DEFAULT_ORDER}, and
     * equal orders are broken alphabetically by namespace — so an addon that
     * wants to be near the front asks for a number below it, and the other
     * twenty-one stay in an order an operator can predict rather than in the
     * order their providers happened to boot.
     */
    public static function settingsOrder(): int;

    /**
     * The icon for this addon's entry in the Control Panel sidebar, as a name
     * from Statamic's own icon set, e.g. `lightning-bolt`.
     *
     * Only the sidebar. The tab itself is text: a bar of twenty-two icons is a
     * row of pictures to decode, and the thing being chosen there is a name.
     */
    public static function settingsIcon(): string;
}
