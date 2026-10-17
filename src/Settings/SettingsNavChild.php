<?php

namespace Goldnead\BrandContext\Settings;

use Statamic\CP\Navigation\NavItem;

/**
 * Der Unterpunkt "Settings" unter der eigenen Navigation eines Addons.
 *
 * Eine eigene Klasse, und der Grund ist die Hervorhebung. Statamic vergleicht
 * fuer "aktiv" die URL **ohne Query**, und alle diese Unterpunkte zeigen auf
 * dieselbe Route, nur mit anderem `?section=`. Ohne diese Klasse waere jeder
 * einzelne gleichzeitig aktiv: in der Seitenleiste leuchteten alle Addons mit
 * Einstellungen auf, und die Brotkrume nennt das erste davon. Gemessen im
 * Playground, 17.10.2026.
 */
class SettingsNavChild extends NavItem
{
    protected string $settingsNamespace = '';

    public function forNamespace(string $namespace): static
    {
        $this->settingsNamespace = $namespace;

        return $this;
    }

    public function isActive()
    {
        $request = request();

        return $this->settingsNamespace !== ''
            && $request->routeIs('statamic.cp.brand-context.settings.index')
            && $request->query('section') === $this->settingsNamespace;
    }
}
