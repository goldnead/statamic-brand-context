<?php

namespace Goldnead\BrandContext\Settings;

use Goldnead\BrandContext\Http\Controllers\Cp\BrandSettingsController;
use Goldnead\BrandContext\ServiceProvider;
use Statamic\Facades\Addon;
use Throwable;

/**
 * The name one addon is known by: the heading over its settings, the label on
 * its tab, and the text of its entry in the Control Panel sidebar.
 *
 * **Its own class because three callers need the same answer.** It lived in
 * {@see BrandSettingsController}
 * while the screen was one long page with one nav entry. Since 22.09.2026 the
 * screen is a tab bar and the sidebar carries one entry per addon, built in
 * {@see ServiceProvider}, which is nowhere near a
 * controller. A second copy of the lookup below is a copy that goes stale the
 * first time an addon is renamed, and the two would then disagree about the
 * name of the same thing on the same screen.
 *
 * Asked of Statamic's addon registry rather than kept as a list here — a
 * second name for an addon is a name that goes stale. Deriving it from the
 * namespace instead is what the first version did, and it put "Leadhub" above
 * a section every other screen in the Control Panel calls "LeadHub".
 *
 * Matched on the slug first and on the package name second, because the two do
 * not always agree: `goldnead/statamic-leadhub` has the slug `leadhub`, but
 * `goldnead/statamic-automations` has `statamic-automations`. A namespace that
 * matches neither falls back to itself made readable, which is still better
 * than an exception on a settings screen.
 *
 * Not cached. The lookup runs once per section per request, `Addon::all()` is
 * Statamic's own cached collection, and a static cache here would be a cache
 * of the installed package set that outlives a test's fake addon.
 */
class AddonTitle
{
    public static function for(string $namespace): string
    {
        try {
            $addon = Addon::all()->first(
                fn ($a) => $a->slug() === $namespace
                    || str_ends_with($a->package(), '/statamic-'.$namespace)
            );

            if ($addon !== null && $addon->name() !== null && $addon->name() !== '') {
                return $addon->name();
            }
        } catch (Throwable) {
            // No addon registry available (a plain Laravel context, or a boot
            // that has not reached Statamic). Falling through is correct: a
            // heading is not worth a broken page, and a nav item that fataled
            // here would take the whole Control Panel with it.
        }

        return ucwords(str_replace(['-', '_'], ' ', $namespace));
    }
}
