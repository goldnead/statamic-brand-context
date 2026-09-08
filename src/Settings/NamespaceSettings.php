<?php

namespace Goldnead\BrandContext\Settings;

use Goldnead\BrandContext\Models\BrandSetting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One addon's settings, for one brand.
 *
 * Obtained from {@see SettingsManager::for()} and never constructed directly:
 * the brand it belongs to is fixed at construction, so an instance cannot
 * quietly start answering for a different tenant halfway through a request.
 */
class NamespaceSettings
{
    /** @var array<string, mixed>|null */
    protected ?array $memo = null;

    public function __construct(
        protected string $namespace,
        protected ?int $brandId,
        protected SettingsRegistry $registry,
        protected SettingsManager $manager,
        protected ConfigRepository $config,
    ) {}

    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * The stored overrides, keyed by dotted path.
     *
     * Read on every boot, including the boot of every queue worker job, so it
     * is cached. A missing table (installed but not migrated) or an
     * unreachable cache is not fatal and must not be: no overrides means the
     * config file, which is exactly the behaviour before this layer existed.
     * An install that cannot read this table still has to serve pages.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        if ($this->brandId === null) {
            return $this->memo = [];
        }

        try {
            return $this->memo = Cache::rememberForever($this->cacheKey(), fn () => $this->read());
        } catch (\Throwable) {
            try {
                return $this->memo = $this->read();
            } catch (\Throwable) {
                return $this->memo = [];
            }
        }
    }

    /** @return array<string, mixed> */
    protected function read(): array
    {
        return BrandSetting::query()
            ->where('brand_id', $this->brandId)
            ->where('namespace', $this->namespace)
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * The effective value of one key: the brand's override if there is one,
     * else whatever the config file says.
     *
     * Reads the live config rather than the stored row, because
     * {@see SettingsManager::apply()} has already put the override there. Two
     * readers of the same value that resolve it differently is the defect this
     * layer exists to remove, so there is only ever one resolution path.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $root = $this->registry->configPath($this->namespace);

        if ($root === null || $root === '') {
            return $default;
        }

        return $this->config->get($root.'.'.$key, $default);
    }

    /**
     * Every offered field's effective value, keyed by dotted path. This is
     * what the screen is drawn from.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];

        foreach (array_keys($this->registry->fields($this->namespace)) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Write the changed settings for this brand.
     *
     * A value equal to the packaged default is **deleted** rather than stored,
     * so "back to default" is reachable from the form and the table does not
     * fill up with rows pinning a value to what it already was. Pinning would
     * also freeze the site against future package upgrades without saying so.
     *
     * @param  array<string, mixed>  $values  key => value, keys from the registry
     */
    public function save(array $values): void
    {
        if ($this->brandId === null) {
            // No brand, no row to own the value. Silently writing it onto the
            // default brand would put one tenant's setting on another's
            // record; refusing is the only safe answer, and it is loud because
            // the caller asked to change something and nothing changed.
            throw new RuntimeException(
                "Cannot save [{$this->namespace}] settings: no brand is resolved for this request."
            );
        }

        $fields = $this->registry->fields($this->namespace);
        $root = $this->registry->configPath($this->namespace);

        // One transaction for the whole section, as the per-addon version this
        // replaces had. A section is saved as a unit from one form; a write
        // that fails halfway leaves the operator looking at a screen where
        // some of what they changed took and some did not, with nothing on it
        // saying which.
        DB::transaction(function () use ($values, $fields, $root) {
            $this->write($values, $fields, $root);
        });

        $this->forget();
        $this->manager->apply(force: true);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $fields
     */
    protected function write(array $values, array $fields, ?string $root): void
    {
        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $value = $this->coerce($fields[$key], $value);

            // Strict, and for a `list` that means order counts.
            //
            // Reviewed and kept on 06.09.2026 against the proposal to compare
            // lists as sets: the same entries in a different order would then
            // delete the row, and the operator's ordering would be replaced by
            // the packaged one on the next boot. For a list where order means
            // nothing that is tidier; for one where it means something — a
            // priority list, a match order — it is silent data loss. Nothing
            // in the field definition says which kind a list is, so the safe
            // reading of a reordered list is "changed". The cost is a row that
            // pins a value to its default until the operator types the default
            // order back, which is visible and reversible. The other way round
            // is neither.
            if ($value === $this->manager->packagedDefault($this->namespace, $key)) {
                BrandSetting::query()
                    ->where('brand_id', $this->brandId)
                    ->where('namespace', $this->namespace)
                    ->where('key', $key)
                    ->delete();

                // Put the file's value back on the live config by hand. The
                // apply below only writes overrides that exist, so a deleted
                // one would leave the old value standing in this process: the
                // row is gone, the screen says "default", and everything
                // reading config() until the next boot still gets the value
                // that was just taken away.
                if ($root !== null && $root !== '') {
                    $this->config->set($root.'.'.$key, $value);

                    // Und gemeldet, denn das hier ist der zweite Schreiber auf
                    // den Root. Solange fuer ihn noch keine Baseline steht — ein
                    // Addon, das seine Config erst in `bootAddon()`
                    // zusammenfuehrt — wuerde `baselineFor()` diesen Wert sonst
                    // beim naechsten Lesen fuer die Paketvorgabe halten und ihn
                    // dauerhaft festhalten.
                    $this->manager->markConfigRootWritten($root);
                }

                continue;
            }

            BrandSetting::query()->updateOrCreate(
                ['brand_id' => $this->brandId, 'namespace' => $this->namespace, 'key' => $key],
                ['value' => $value],
            );
        }
    }

    /**
     * Put one value into the type its field declares.
     *
     * Here rather than only in the Control Panel controller, which is where it
     * used to live alone. HTML form controls hand back strings, so the
     * controller had to do it — but a console command, a data migration or a
     * call through the `BrandSettings` facade reaches this method without ever
     * passing that controller, and a `"30"` stored where an integer belongs is
     * a bug waiting for a strict comparison: it never equals the packaged
     * default, so the row can never be deleted, and every reader of
     * `config()` gets a string where the code expects a number.
     *
     * @param  array<string, mixed>  $field
     */
    protected function coerce(array $field, mixed $value): mixed
    {
        // An empty value in a nullable field means "unset", which is a real
        // and distinct state — an unset retention is "same as the packaged
        // default", not zero days.
        if (($field['nullable'] ?? false) && ($value === null || $value === '')) {
            return null;
        }

        return match ($field['type'] ?? 'string') {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'list' => $this->coerceList($field, $value),
            // `select` included in the default arm on purpose: the option keys
            // are strings, and the validation has already refused anything
            // that is not one of them.
            default => (string) $value,
        };
    }

    /**
     * A list, with its entries in the type the field declares.
     *
     * `items` defaults to `string`, which is what a textarea hands back and
     * what most of these lists are. It exists because the alternative was a
     * quiet trap: a list whose packaged default is `[500, 502, 504]` comes
     * back from the form as `["500", "502", "504"]`, which never equals the
     * default under a strict comparison. The row could then **never** be
     * deleted — the field stayed pinned to its own value forever, a later
     * release could not move it, and any reader doing `in_array($code, …,
     * true)` silently stopped matching. Found in `webhook-manager`'s retry
     * status list on 06.09.2026.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    protected function coerceList(array $field, mixed $value): array
    {
        $items = array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), (array) $value),
            fn (string $item) => $item !== '',
        ));

        return match ($field['items'] ?? 'string') {
            'integer' => array_map(static fn (string $item) => (int) $item, $items),
            default => $items,
        };
    }

    /** Drop the cached overrides so the next read sees the table. */
    public function forget(): void
    {
        $this->memo = null;

        if ($this->brandId === null) {
            return;
        }

        try {
            Cache::forget($this->cacheKey());
        } catch (\Throwable) {
            // Running without a cache store is not a failure to save: read()
            // then hits the table on every call anyway.
        }
    }

    protected function cacheKey(): string
    {
        return 'brand-context.settings.'.$this->brandId.'.'.$this->namespace;
    }
}
