<?php

namespace Goldnead\BrandContext\Settings;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Which addons have announced a settings screen, in the order they announced
 * it.
 *
 * An addon registers in its `boot()`, the same way it would register a
 * fieldtype. Nothing scans the filesystem and nothing reads composer metadata:
 * an addon that is installed but has not registered simply has no settings,
 * which is the correct answer for the twenty-one packages in the suite that
 * offer none.
 *
 * Held as a singleton for the process. It is deliberately not cached across
 * requests — the contents are class names decided at boot by which providers
 * ran, so a cache of it would be a cache of the installed package set, which
 * Composer already owns.
 */
class SettingsRegistry
{
    /** @var array<string, class-string<ProvidesSettings>> */
    protected array $providers = [];

    /**
     * Wer sich nicht anmelden konnte, und warum.
     *
     * @var array<string, array{addon: string, reason: string}>
     */
    protected array $failures = [];

    /**
     * Announce an addon's settings.
     *
     * Registering the same namespace twice is a programming error, not a
     * merge: two packages claiming `automations` would write to each other's
     * rows, and whichever booted last would silently win. Refusing here turns
     * that into a failure at install time instead of a support ticket about
     * settings that revert themselves.
     *
     * **Eine fehlerhafte Anmeldung nimmt nicht die Installation mit.** Diese
     * Methode laeuft im `boot()` eines fremden Addons. Solange sie geworfen hat,
     * war jede kaputte Anmeldung eine kaputte Installation: das Control Panel
     * antwortete mit 500, und zwar vollstaendig — auch fuer die achtzehn
     * Addons, die nichts dafuer konnten. Am Playground ist genau das am
     * 07.09.2026 viermal passiert (fehlender Import, undefinierte Methode, eine
     * Klasse, die den Vertrag nicht erfuellt). Nach dem Veroeffentlichen wird
     * daraus die lahmgelegte Installation eines Kunden wegen eines einzigen
     * fehlerhaft ausgelieferten Addons.
     *
     * Deshalb: laut ins Log, Abschnitt faellt weg, Rest laeuft weiter. Was
     * weggefallen ist, steht auf der Einstellungsseite selbst
     * ({@see failures()}) — ein still fehlender Abschnitt waere schlimmer als
     * der Absturz, weil ihn niemand bemerkt.
     *
     * **Auch mit `APP_DEBUG` wird nicht mehr geworfen.** Die erste Fassung tat
     * das, mit dem Argument, ein Addon-Entwickler solle seinen Fehler sofort
     * sehen. Der Playground faehrt `APP_DEBUG=true`, und dort arbeiten acht
     * Trupps gleichzeitig: am 07.09.2026 lag er deswegen zwanzig Minuten auf
     * 500, weil `Goldnead\Notifications\Settings` den Vertrag nicht erfuellte.
     * Der Ausnahmezweig war also genau in der Umgebung scharf, in der der
     * Schaden am groessten ist. Der Entwickler sieht seinen Fehler weiterhin,
     * nur ohne die Installation der anderen: mit Ursache im Log und als roter
     * Kasten mit Klassenname und Grund auf der Einstellungsseite.
     *
     * @param  class-string<ProvidesSettings>  $provider
     */
    public function register(string $provider): void
    {
        try {
            $this->add($provider);
        } catch (Throwable $e) {
            $this->fail($provider, $e);
        }
    }

    /**
     * @param  class-string<ProvidesSettings>  $provider
     */
    protected function add(string $provider): void
    {
        if (! is_subclass_of($provider, ProvidesSettings::class)) {
            throw new InvalidArgumentException(
                sprintf('[%s] must implement %s to register settings.', $provider, ProvidesSettings::class)
            );
        }

        $namespace = $provider::settingsNamespace();

        if ($namespace === '') {
            throw new InvalidArgumentException(sprintf('[%s] returned an empty settings namespace.', $provider));
        }

        if (isset($this->providers[$namespace]) && $this->providers[$namespace] !== $provider) {
            throw new InvalidArgumentException(sprintf(
                'The settings namespace [%s] is already registered by [%s]; [%s] cannot take it as well.',
                $namespace,
                $this->providers[$namespace],
                $provider
            ));
        }

        $this->providers[$namespace] = $provider;
    }

    /**
     * Einen Ausfall festhalten: ins Log, in die Liste, und die Anmeldung faellt
     * weg.
     *
     * Die Anmeldung faellt bewusst ganz weg statt halb zu stehen. Ein Addon,
     * dessen `settingsGroups()` wirft, hat keine Feldliste; es als registriert
     * mit null Feldern stehen zu lassen ergaebe einen leeren Abschnitt, den
     * niemand als Fehler liest.
     */
    protected function fail(string $addon, Throwable $e): void
    {
        if (isset($this->providers[$addon])) {
            unset($this->providers[$addon]);
        }

        $this->note(
            $addon,
            $addon,
            $e->getMessage() !== '' ? $e->getMessage() : $e::class,
            $e,
        );
    }

    /**
     * Einen Ausfall in die Liste und ins Log schreiben.
     *
     * `$id` haelt die Eintraege auseinander, ohne die Liste wachsen zu lassen:
     * `groups()` wird je Anfrage mehrfach gefragt, und ein Ausfall, der bei
     * jedem Aufruf eine weitere Zeile erzeugt, waere auf der Seite eine
     * Wiederholung derselben Meldung.
     */
    protected function note(string $id, string $addon, string $reason, ?Throwable $e = null): void
    {
        if (isset($this->failures[$id])) {
            return;
        }

        $this->failures[$id] = ['addon' => $addon, 'reason' => $reason];

        try {
            Log::error(
                sprintf('[brand-context] [%s] wird auf der Einstellungsseite ausgelassen: %s', $addon, $reason),
                $e === null ? [] : ['exception' => $e],
            );
        } catch (Throwable) {
            // Ein Logger, der selbst nicht kann, darf nicht das sein, was die
            // Installation doch noch umwirft. Die Liste traegt den Fall
            // weiterhin auf die Seite.
        }
    }

    /**
     * Eine statische Frage an einen Anbieter stellen, ohne dass seine Antwort
     * die Installation umwerfen kann.
     *
     * Vier Wege fuehren in fremden Code — {@see register()},
     * {@see configPath()}, {@see permission()}, {@see groups()} — und drei
     * davon laufen aus `app->booted()`. Ein Fang allein bei der Anmeldung
     * haette die anderen offen gelassen: ein Addon, das sich sauber anmeldet
     * und erst beim Aufzaehlen seiner Felder wirft, nimmt die Installation
     * genauso mit.
     *
     * @param  callable(class-string<ProvidesSettings>): mixed  $frage
     */
    protected function ask(string $namespace, callable $frage, mixed $fallback): mixed
    {
        $provider = $this->provider($namespace);

        if ($provider === null) {
            return $fallback;
        }

        try {
            return $frage($provider);
        } catch (Throwable $e) {
            $this->fail($namespace, $e);

            return $fallback;
        }
    }

    /**
     * Was sich nicht anmelden konnte, fuer die Anzeige auf der
     * Einstellungsseite.
     *
     * @return array<int, array{addon: string, reason: string}>
     */
    public function failures(): array
    {
        return array_values($this->failures);
    }

    /** @return array<string, class-string<ProvidesSettings>> */
    public function all(): array
    {
        return $this->providers;
    }

    public function has(string $namespace): bool
    {
        return isset($this->providers[$namespace]);
    }

    /** @return class-string<ProvidesSettings>|null */
    public function provider(string $namespace): ?string
    {
        return $this->providers[$namespace] ?? null;
    }

    /**
     * The config root a namespace's unset values keep following.
     *
     * Null for an unregistered namespace rather than a guess. A wrong root
     * here would write overrides onto some other package's config.
     */
    public function configPath(string $namespace): ?string
    {
        return $this->ask($namespace, fn (string $p) => $p::settingsConfigPath(), null);
    }

    /**
     * The permission that gates a namespace's section.
     *
     * Null for an unregistered namespace, and null must be treated as "refuse"
     * by every caller — an unknown namespace with no permission attached is
     * the one case where falling back to "allow" would hand out an ungated
     * screen.
     */
    public function permission(string $namespace): ?string
    {
        return $this->ask($namespace, fn (string $p) => $p::settingsPermission(), null);
    }

    /**
     * Die Feldliste eines Addons.
     *
     * Ebenfalls abgesichert, und das ist nicht dieselbe Stelle wie
     * {@see register()}: `fields()` ruft hierher, und `fields()` laeuft aus
     * {@see SettingsManager::applyNamespace()}, also aus `app->booted()`. Ein
     * Addon, das sich sauber anmeldet und erst beim Aufzaehlen seiner Felder
     * wirft — ein fehlender Import in `settingsGroups()`, ein `__()` auf einer
     * Klasse, die es nicht gibt — nahm die Installation also genauso mit wie
     * eine kaputte Anmeldung.
     *
     * @return array<int, array{title: string, description?: string, fields: array<int, array<string, mixed>>}>
     */
    public function groups(string $namespace): array
    {
        $groups = $this->ask($namespace, fn (string $p) => $p::settingsGroups(), []);

        foreach ($groups as $g => $group) {
            $groups[$g]['fields'] = array_values(array_filter(
                $group['fields'] ?? [],
                fn (array $field) => $this->fieldIsUsable($namespace, $field),
            ));
        }

        return $groups;
    }

    /**
     * Ob ein Feld ueberhaupt angeboten werden kann.
     *
     * Bisher nur eine Frage, und es ist die, an der ein ganzer Abschnitt
     * unbedienbar wurde: **ein `boolean`-Feld, dessen Paketvorgabe kein
     * Wahrheitswert ist.** Gemessen an `statamic-preference-center`, dessen
     * `sources.*` in der Config auf dem String `'auto'` stehen. Die Seite legt
     * den Wert in einen Schalter, der Schalter gibt ihn unveraendert zurueck,
     * und `UpdateBrandSettingsRequest` weist ihn mit `boolean` ab — auf einer
     * frischen Installation ist damit der ganze Abschnitt unspeicherbar, bis
     * jemand jedes betroffene Feld von Hand anfasst.
     *
     * **Ausgelassen, nicht umgewandelt.** `'auto'` als `true` zu lesen waere
     * bequem und falsch: `auto` ist ein dritter Zustand, und ihn beim ersten
     * Speichern still zu `an` zu machen aendert das Verhalten der
     * Installation, ohne dass jemand es angeordnet hat. Ein Feld mit drei
     * Zustaenden ist ein `select`, kein `boolean` — und genau das soll der
     * Addon-Autor lesen, statt es zu raten.
     *
     * **Feldweise, nicht abschnittsweise.** Die anderen zwoelf Felder des
     * Abschnitts sind in Ordnung und bleiben bedienbar. Was fehlt, steht mit
     * Feldnamen und gefundenem Typ auf der Seite.
     *
     * Geprueft wird genau das, was Laravels `boolean`-Regel spaeter abweist:
     * `true`, `false`, `1`, `0`, `"1"`, `"0"` kommen durch, weil sie beim
     * Speichern durchkommen. Alles andere ist ein Feld, das nie zu speichern
     * waere.
     *
     * @param  array<string, mixed>  $field
     */
    protected function fieldIsUsable(string $namespace, array $field): bool
    {
        $key = $field['key'] ?? null;

        if (! is_string($key) || ($field['type'] ?? null) !== 'boolean') {
            return true;
        }

        $root = $this->configPath($namespace);

        if ($root === null || $root === '') {
            return true;
        }

        $value = config($root.'.'.$key);

        if ($value === null || in_array($value, [true, false, 1, 0, '1', '0'], true)) {
            return true;
        }

        $this->note(
            $namespace.'|'.$key,
            $namespace,
            sprintf(
                'Das Feld [%s] ist als boolean deklariert, in der Config steht dort aber ein %s. Solange das so ist, laesst sich der Abschnitt nicht speichern, deshalb wird das Feld ausgelassen. Ein Wert mit mehr als zwei Zustaenden gehoert als select deklariert.',
                $key,
                get_debug_type($value),
            ),
        );

        return false;
    }

    /**
     * A namespace's fields flattened to `key => field`, which is the shape the
     * validation, the store and the screen all actually want.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $namespace): array
    {
        $fields = [];

        foreach ($this->groups($namespace) as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * A select field's options, in one shape whatever the addon wrote.
     *
     * Two spellings are accepted, because both read naturally and both turned
     * up in the suite on the first day this existed:
     *
     * - `['new' => 'Neu', 'won' => 'Gewonnen']` — a config-shaped map, which is
     *   how a set of allowed config values reads;
     * - `[['value' => 'new', 'label' => 'Neu'], …]` — a list, which is what
     *   Statamic's own `Select` component takes.
     *
     * Normalising here rather than picking one and correcting the addons: the
     * cost of guessing wrong is not cosmetic. Reading a list as a map turns the
     * allowed values into `[0, 1, 2]`, and the validation then refuses every
     * real answer — nobody can save the field at all, and the message says the
     * value is invalid rather than that the contract was misread.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(string $namespace, string $key): array
    {
        return static::normaliseOptions($this->fields($namespace)[$key]['options'] ?? []);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function normaliseOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $out = [];

        foreach ($options as $key => $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $out[] = [
                    'value' => (string) $option['value'],
                    'label' => (string) ($option['label'] ?? $option['value']),
                ];

                continue;
            }

            if (is_scalar($option)) {
                $out[] = ['value' => (string) $key, 'label' => (string) $option];
            }
        }

        return $out;
    }

    /**
     * Drop everything. Tests only — a registry emptied at runtime would take
     * every registered screen down with it.
     */
    public function flush(): void
    {
        $this->providers = [];
        $this->failures = [];
    }
}
