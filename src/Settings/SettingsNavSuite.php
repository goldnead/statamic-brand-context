<?php

namespace Goldnead\BrandContext\Settings;

use Statamic\CP\Navigation\NavItem;

/**
 * Der eine Eintrag "Addon Suite" in der Sektion "Settings".
 *
 * Statamic vergleicht fuer "aktiv" die URL ohne Query. Die Einzelseite eines
 * Addons (`?section=<ns>`) liegt auf derselben URL, und ohne diese Klasse waere
 * der Suite-Eintrag dort mit aktiv, neben dem Unterpunkt des Addons. Aktiv ist
 * er nur auf der allgemeinen Seite: ohne `section`, oder mit einem, das kein
 * Addon mit eigener Nav nennt (dann oeffnet die allgemeine Seite den Tab).
 */
class SettingsNavSuite extends NavItem
{
    public function isActive()
    {
        $request = request();

        if (! $request->routeIs('statamic.cp.brand-context.settings.index')) {
            return false;
        }

        $section = $request->query('section');

        return ! (is_string($section) && app(NavPlacement::class)->isViaParent($section));
    }
}
