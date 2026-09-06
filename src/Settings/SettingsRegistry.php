<?php

namespace Goldnead\BrandContext\Settings;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use InvalidArgumentException;

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
     * Announce an addon's settings.
     *
     * Registering the same namespace twice is a programming error, not a
     * merge: two packages claiming `automations` would write to each other's
     * rows, and whichever booted last would silently win. Throwing here turns
     * that into a failure at install time instead of a support ticket about
     * settings that revert themselves.
     *
     * @param  class-string<ProvidesSettings>  $provider
     */
    public function register(string $provider): void
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
        $provider = $this->provider($namespace);

        return $provider === null ? null : $provider::settingsConfigPath();
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
        $provider = $this->provider($namespace);

        return $provider === null ? null : $provider::settingsPermission();
    }

    /**
     * @return array<int, array{title: string, description?: string, fields: array<int, array<string, mixed>>}>
     */
    public function groups(string $namespace): array
    {
        $provider = $this->provider($namespace);

        return $provider === null ? [] : $provider::settingsGroups();
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
    }
}
