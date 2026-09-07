<?php

namespace Goldnead\BrandContext\Settings;

use Goldnead\BrandContext\BrandManager;
use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\BrandContext\Queue\BrandOnQueue;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The settings layer: one place that knows every registered namespace, the
 * overrides stored for the current brand, and how to push them onto the live
 * config.
 *
 * **Why the config is overridden rather than read through a facade.** The
 * three addons this generalises read their settings as
 * `config('automations.runs.prune_after_days')` in dozens of places. Teaching
 * every one of those call sites about a new reader would be a migration
 * measured in call sites, and a single missed one is a setting that looks
 * changed on screen and is not in effect. Overriding the config keeps every
 * existing reader correct without being touched.
 * {@see NamespaceSettings::get()} is the explicit reader for new code that
 * wants to be unambiguous about where a value came from.
 *
 * **Why the baseline is restored before every apply, unlike the per-addon
 * version this replaces.** `automations` applied its overrides once at boot
 * and never had to undo them, because there was only ever one set. With brands
 * there is one set per brand and the process serves several in turn: a queue
 * worker running brand A's job after brand B's, a `RunsForEachBrand` command,
 * the Control Panel with the switcher. Applying B and then applying A on top
 * would leave every key A has not overridden still holding B's value — a
 * failure that is invisible, wrong only for the keys one brand customised and
 * the other did not, which is exactly the set nobody thinks to check. So each
 * apply resets the namespace to its packaged baseline first.
 */
class SettingsManager
{
    /**
     * What the config files say, per config root, captured before any override
     * was written over them.
     *
     * Never re-read from `config()` after the first apply: by then the live
     * value *is* the override, so a later snapshot would record an override as
     * the packaged default. A value reset to the file's default would then be
     * stored as a row instead of deleted, which breaks the one property this
     * whole layer promises — that unset means "follow the config file".
     *
     * @var array<string, mixed>
     */
    protected array $baseline = [];

    /** The brand id the live config currently reflects, if any. */
    protected ?int $appliedFor = null;

    /** Whether apply() has ever run, which is not the same as $appliedFor. */
    protected bool $everApplied = false;

    /**
     * The namespaces the live config reflects.
     *
     * Kept alongside the brand id because the cheap exit below has to answer
     * "is the config already correct", and the brand alone does not decide
     * that. An addon registering after the first apply — a late `bootAddon()`,
     * an addon enabled at runtime, an Octane worker taking a request against a
     * container that booted differently — would otherwise never have its
     * overrides pushed at all: the operator saves, the row lands, the toast
     * says saved, and `config()` keeps answering with the packaged default for
     * the rest of the process. On a single-brand install nothing ever changes
     * brand, so nothing would ever correct it.
     *
     * @var array<int, string>
     */
    protected array $appliedNamespaces = [];

    /**
     * The config keys this layer wrote, per namespace, at the last apply.
     *
     * Kept so an apply can take back exactly its own writes and nothing else.
     * See {@see applyNamespace()} for what resetting the whole root cost.
     *
     * @var array<string, array<int, string>>
     */
    protected array $applied = [];

    /** @var array<string, NamespaceSettings> */
    protected array $scopes = [];

    public function __construct(
        protected SettingsRegistry $registry,
        protected BrandManager $brands,
        protected ConfigRepository $config,
    ) {}

    public function registry(): SettingsRegistry
    {
        return $this->registry;
    }

    /**
     * The settings of one namespace, for the current brand.
     *
     * Memoised per namespace *and* per brand: the object caches the brand's
     * overrides, so handing back a brand A scope while brand B is current
     * would answer with the wrong site's values.
     */
    public function for(string $namespace): NamespaceSettings
    {
        $brandId = $this->currentBrandId();

        return $this->scopes[$brandId.'|'.$namespace] ??= new NamespaceSettings(
            $namespace,
            $brandId,
            $this->registry,
            $this,
            $this->config,
        );
    }

    /**
     * Push the current brand's overrides onto the live config, for every
     * registered namespace.
     *
     * Cheap to call repeatedly: it returns immediately when the config already
     * reflects the brand asked for.
     */
    public function apply(bool $force = false): void
    {
        $brandId = $this->currentBrandId();
        $namespaces = array_keys($this->registry->all());

        if (! $force
            && $this->everApplied
            && $this->appliedFor === $brandId
            && $this->appliedNamespaces === $namespaces) {
            return;
        }

        foreach ($namespaces as $namespace) {
            $this->applyNamespace($namespace, $brandId);
        }

        $this->appliedFor = $brandId;
        $this->appliedNamespaces = $namespaces;
        $this->everApplied = true;
    }

    /**
     * Ein Addon anwenden, das sich nach dem ersten {@see apply()} angemeldet
     * hat.
     *
     * Gerufen aus {@see SettingsRegistry::register()}, also genau einmal je
     * Nachzuegler — nicht einmal je Bootzyklus. Der Unterschied ist der Grund
     * fuer diese Methode: ein zweites `apply()` hinterherzuschieben waere beim
     * uebernaechsten Addon wieder zu frueh gewesen.
     *
     * Nichts passiert, solange `apply()` nie lief. Dann steht die Anmeldung
     * rechtzeitig, und das erste `apply()` aus `app->booted()` nimmt sie
     * ohnehin mit.
     */
    public function applyLate(string $namespace): void
    {
        if (! $this->everApplied) {
            return;
        }

        // `appliedFor`, nicht `currentBrandId()`. Zwei Gruende, und beide sind
        // gemessen.
        //
        // Fachlich: die Aufgabe ist, diesen einen Namensraum in denselben
        // Zustand zu bringen wie die uebrige Config — und die steht auf der
        // Marke, fuer die zuletzt angewandt wurde. Ist die Marke inzwischen
        // gewechselt, hat {@see brandChanged()} ohnehin schon alles neu gelegt
        // und `appliedFor` mitgezogen; ein Wechsel ohne dieses Nachziehen waere
        // fuer alle Namensraeume falsch, nicht nur fuer den neuen.
        //
        // Praktisch: `currentBrandId()` fragt `BrandManager::default()`, und
        // das merkt sich die Standardmarke fuer den Rest des Prozesses. Eine
        // Anmeldung ist der falsche Anlass dafuer. Der Test „still draws the
        // screen on an install whose migrations never ran" faellt genau
        // darueber um.
        $this->applyNamespace($namespace, $this->appliedFor);

        // Frisch aus der Registry statt angehaengt: der billige Ausstieg in
        // apply() vergleicht diese Liste mit `array_keys($registry->all())`,
        // und die beiden muessen Zeichen fuer Zeichen uebereinstimmen, sonst
        // laeuft jeder weitere apply() wieder ueber alles.
        $this->appliedNamespaces = array_keys($this->registry->all());
    }

    /**
     * Reset one namespace to its packaged values and write this brand's
     * overrides over the top.
     */
    protected function applyNamespace(string $namespace, ?int $brandId): void
    {
        $root = $this->registry->configPath($namespace);

        if ($root === null || $root === '') {
            return;
        }

        // Erst die Paketwerte festhalten, dann darueber schreiben. Vor allem
        // anderen in dieser Methode.
        //
        // `baselineFor()` sammelt lazy ein, und ohne diese Zeile war der erste
        // Zugriff auf einen unveraenderten Root nicht dieses `apply()`, sondern
        // `packagedDefault()` beim naechsten Speichern — die Restore-Schleife
        // unten laeuft beim ersten Anwenden ueber eine leere Liste und ruft
        // nichts. Bis dahin lag die Ueberschreibung aber laengst auf der Config,
        // und die Baseline hielt sie fuer die Paketvorgabe.
        //
        // Was das kostete (gefunden 07.09.2026 an `statamic-offers`,
        // `seller.contact`): der Betreiber speichert denselben Wert ein zweites
        // Mal, `$value === packagedDefault()` trifft zu, und die Zeile wird als
        // "entspricht ohnehin dem Default" geloescht. Der Wert faellt beim
        // naechsten Aufruf auf die Paketvorgabe zurueck, ohne Fehler und ohne
        // Meldung. In einem einzigen Prozess ist das unsichtbar: dort wird die
        // Baseline beim ersten Speichern noch von der sauberen Config genommen.
        //
        // Das Loeschen bleibt richtig — eine Zeile, die einen Wert auf seinen
        // eigenen Default festnagelt, friert die Site gegen kuenftige Upgrades
        // ein. Nur der Vergleichswert muss der aus der Datei sein und nicht der,
        // der gerade in der Config steht.
        $this->baselineFor($root);

        // Undo only what the previous apply wrote, key by key.
        //
        // The first version reset the whole config root
        // (`config->set($root, $baseline)`) and that was wrong in a way no
        // test in this package could see: it also discarded every runtime
        // `config()->set('<addon>.…')` made since boot. A host adjusting a
        // feature flag, a middleware, a feature-flag package — all of it
        // silently reverted at the next brand switch, and `RunsForEachBrand`
        // switches on every iteration inside a queue worker. Measured in
        // `statamic-leadhub` on 06.09.2026: a runtime flag set to true read
        // false again immediately after `setCurrent()`, and 20 of that
        // addon's tests went red for no reason of its own.
        //
        // Only keys this layer itself put there are taken back. Anything else
        // in the root was never ours to touch.
        foreach ($this->applied[$namespace] ?? [] as $key) {
            $this->config->set($root.'.'.$key, data_get($this->baselineFor($root), $key));
        }

        $this->applied[$namespace] = [];

        // No brand resolved means no rows can be read safely — the brand scope
        // fails closed by design — so the packaged values are the answer,
        // which is what the restore above just put back.
        if ($brandId === null) {
            return;
        }

        $fields = $this->registry->fields($namespace);

        foreach ($this->for($namespace)->overrides() as $key => $value) {
            // Only keys the addon still offers. A row left behind by an older
            // release must not be able to set an arbitrary config path: that
            // is a stored value from a previous version deciding what a later
            // version does, which nobody can see and nobody asked for.
            if (! isset($fields[$key])) {
                continue;
            }

            $this->config->set($root.'.'.$key, $value);
            $this->applied[$namespace][] = $key;
        }
    }

    /**
     * The config as the files have it for one root.
     *
     * Taken from the live config the first time, which is correct only because
     * it happens before anything is applied — and a host that edited its own
     * published `config/automations.php` must be able to get back to *its*
     * value, not to the copy inside the package.
     */
    public function baselineFor(string $root): mixed
    {
        return $this->baseline[$root] ??= $this->config->get($root, []);
    }

    /** What one key's packaged default is, ignoring any applied override. */
    public function packagedDefault(string $namespace, string $key): mixed
    {
        $root = $this->registry->configPath($namespace);

        if ($root === null || $root === '') {
            return null;
        }

        return data_get($this->baselineFor($root), $key);
    }

    /**
     * Called when the current brand changes.
     *
     * The live config belongs to whichever brand was applied last, so the
     * change has to be pushed through before anything reads `config()` again.
     * Applying eagerly rather than marking dirty is deliberate: a lazy flag
     * would only be honoured by readers that go through this class, and the
     * whole point of the design is that most readers do not.
     *
     * Nothing happens if apply() has never run — an installation with no
     * settings screens, or a boot that has not reached it yet, must not have
     * its config rewritten by a brand switch.
     */
    public function brandChanged(): void
    {
        $this->scopes = [];

        if ($this->everApplied) {
            $this->apply(force: true);
        }
    }

    /**
     * Forget cached overrides. Pass nothing to drop every namespace of the
     * current brand.
     */
    public function forget(?string $namespace = null): void
    {
        if ($namespace !== null) {
            $this->for($namespace)->forget();
            unset($this->scopes[$this->currentBrandId().'|'.$namespace]);

            return;
        }

        foreach (array_keys($this->registry->all()) as $each) {
            $this->for($each)->forget();
        }

        $this->scopes = [];
    }

    /**
     * The brand whose settings are in force.
     *
     * Null when no brand can be resolved — a console command that never set
     * one, or an install whose migrations have not run. Null means "packaged
     * values", never "some brand's values": guessing a brand here would serve
     * one tenant's configuration to another.
     *
     * **`hasCurrent()`, not `currentId()`.** `currentId()` falls back to the
     * default brand, and asking it here disagreed with {@see BrandScope},
     * which fails closed when nothing is current. The two answers together
     * were worse than either alone: this class believed it was serving brand
     * 1, the scope returned no rows, and the empty result was written into the
     * cache **under brand 1's key, forever**. The next request that really did
     * have brand 1 current then read that poisoned entry and ran on packaged
     * defaults, site-wide, until somebody cleared the cache by hand.
     *
     * A queued job is unaffected: {@see BrandOnQueue}
     * carries the brand into the worker, so `hasCurrent()` is true there. A
     * console command that has to touch every brand uses
     * {@see RunsForEachBrand}, which sets one.
     * A command that sets none genuinely has no brand, and packaged values are
     * the only honest answer.
     */
    protected function currentBrandId(): ?int
    {
        try {
            if (! $this->brands->hasCurrent()) {
                return null;
            }

            return $this->brands->currentId();
        } catch (\Throwable) {
            return null;
        }
    }
}
