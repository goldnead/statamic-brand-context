<?php

namespace Goldnead\BrandContext\Settings;

use Statamic\CP\Navigation\NavItem;
use Statamic\Facades\CP\Nav;
use Throwable;

/**
 * Wo die Einstellungen eines Addons in der Seitenleiste haengen.
 *
 * Zwei Orte, und jedes Addon hat genau einen:
 *
 * 1. **Als Unterpunkt unter dem eigenen Nav-Eintrag**, wenn das Addon per
 *    `settingsNavParent()` sagt, welcher das ist, und dieser Eintrag zur
 *    Laufzeit gefunden wird und der Benutzer ihn sehen darf. Die Seite zeigt
 *    dann nur dieses Addon.
 * 2. **Auf der allgemeinen Seite der Suite** (ein Eintrag in der Sektion
 *    "Settings", Tabs nur fuer diese Addons) in allen anderen Faellen. Das ist
 *    der Rueckweg, und er ist absichtlich der Normalfall bei jedem Zweifel:
 *    ein Addon, das nirgends mehr erreichbar ist, ist schlimmer als eines am
 *    falschen Ort.
 *
 * **Die Entscheidung faellt einmal, im Nav-Rueckruf**, und wird hier
 * gemerkt, damit der Controller dieselbe Antwort liest und nicht eine zweite
 * Rechnung anstellt, die irgendwann anders ausfaellt als die Seitenleiste.
 * Registriert als `scoped`, damit ein langlebiger Prozess (Octane) die
 * Antwort des vorigen Benutzers nicht erbt.
 */
class NavPlacement
{
    /** @var array<string, bool> Namensraum => haengt als Unterpunkt unter seinem Eintrag */
    protected array $placed = [];

    protected bool $warmed = false;

    /**
     * Den Eltern-Eintrag des Addons suchen, den der Benutzer auch sehen darf.
     *
     * Im Rueckruf von `Nav::extend()` aufgerufen. Die Eintraege der Addons
     * stehen dann schon auf der Nav-Instanz, weil der Rueckruf erst nach dem
     * Booten angemeldet wird (siehe `ServiceProvider::registerSettingsScreen()`).
     * Ein Addon, das seine Nav selbst erst spaeter anmeldet, wird nicht
     * gefunden und faellt auf die allgemeine Seite zurueck.
     */
    public function parentItem(object $nav, ?array $parent, object $user): ?NavItem
    {
        if ($parent === null) {
            return null;
        }

        [$section, $name] = $parent;

        $item = $nav->find($section, $name);

        if (! $item instanceof NavItem) {
            return null;
        }

        // Dieselbe Pruefung wie Statamics NavBuilder::filterAuthorizedNavItems().
        // Faellt der Eltern-Eintrag fuer diesen Benutzer weg, nimmt er sein
        // Kind mit, und das Addon waere unerreichbar.
        $authorization = $item->authorization();

        if ($authorization && ! $user->can($authorization->ability, $authorization->arguments)) {
            return null;
        }

        return $item;
    }

    public function remember(string $namespace, bool $viaParent): void
    {
        $this->placed[$namespace] = $viaParent;
    }

    /**
     * Haengt dieses Addon als Unterpunkt unter seinem Eintrag?
     *
     * Hat der Nav-Rueckruf in dieser Anfrage noch nicht gelaufen, wird die
     * Navigation einmal gebaut, damit er laeuft. Ohne Preferences: ein Eintrag,
     * den jemand in seinen Nav-Einstellungen versteckt hat, gehoert dazu, sonst
     * zaehlte ein versteckter Eintrag als erreichbarer.
     *
     * Scheitert das Bauen, gilt "nein" fuer alle: lieber alles auf der
     * allgemeinen Seite als ein Addon ohne Zugang.
     */
    public function isViaParent(string $namespace): bool
    {
        if (! array_key_exists($namespace, $this->placed) && ! $this->warmed) {
            $this->warmed = true;

            try {
                Nav::build(false);
            } catch (Throwable) {
                // Der Rueckweg gilt.
            }
        }

        return $this->placed[$namespace] ?? false;
    }

    /** Fuer Tests und Prozesse, die den Benutzer wechseln. */
    public function flush(): void
    {
        $this->placed = [];
        $this->warmed = false;
    }
}
