<?php

namespace Goldnead\BrandContext;

use Closure;
use Goldnead\BrandContext\Models\Brand;
use RuntimeException;

/**
 * Central brand-context state and mode logic.
 *
 * Bound as a singleton ("brand-context"). Holds the current brand for the
 * request/process and decides whether multi-brand isolation is active.
 */
class BrandManager
{
    protected ?Brand $current = null;

    /** Cache of the default brand for the process. */
    protected ?Brand $default = null;

    /** When true, the global scope is bypassed entirely (explicit admin ops). */
    protected bool $scopeDisabled = false;

    /**
     * Is hard brand isolation active?
     *
     * Requires the config flag AND, if configured, the license gate.
     */
    public function multiBrandEnabled(): bool
    {
        if (! config('brand-context.multi_brand', false)) {
            return false;
        }

        $check = config('brand-context.license_check');

        if ($check === null) {
            return true;
        }

        if (is_string($check) && class_exists($check)) {
            $check = app($check);
        }

        return (bool) (is_callable($check) ? $check() : $check);
    }

    /** The always-present default brand (single-brand target + backfill root). */
    public function default(): Brand
    {
        if ($this->default) {
            return $this->default;
        }

        $brand = Brand::default();

        if (! $brand) {
            throw new RuntimeException(
                'No default brand found. Run migrations for goldnead/statamic-brand-context.'
            );
        }

        return $this->default = $brand;
    }

    public function defaultId(): int
    {
        return $this->default()->id;
    }

    /**
     * The current brand.
     *
     * Single-brand mode: always the default brand.
     * Multi-brand mode: the resolved current brand, or the default as fallback
     * only when nothing has been set (callers that need strict behaviour use
     * hasCurrent()).
     */
    public function current(): Brand
    {
        if (! $this->multiBrandEnabled()) {
            return $this->default();
        }

        // Fall back to default WITHOUT memoizing it as the current brand.
        // Memoizing here would flip hasCurrent() to true and defeat the
        // fail-closed read scope for any code path that calls current() first.
        return $this->current ?? $this->default();
    }

    public function currentId(): int
    {
        return $this->current()->id;
    }

    /** Has an explicit current brand been resolved (multi-brand only)? */
    public function hasCurrent(): bool
    {
        if (! $this->multiBrandEnabled()) {
            return true; // single-brand always has the default as "current"
        }

        return $this->current !== null;
    }

    /** Set the active brand. Accepts a Brand, id, or handle. */
    public function setCurrent(Brand|int|string|null $brand): static
    {
        if ($brand === null) {
            $this->current = null;

            return $this;
        }

        $this->current = $this->resolveBrand($brand);

        return $this;
    }

    public function forget(): static
    {
        $this->current = null;

        return $this;
    }

    protected function resolveBrand(Brand|int|string $brand): Brand
    {
        if ($brand instanceof Brand) {
            return $brand;
        }

        $query = Brand::query();

        $resolved = is_int($brand)
            ? $query->find($brand)
            : $query->where('handle', $brand)->first();

        if (! $resolved) {
            throw new RuntimeException("Unknown brand [{$brand}].");
        }

        return $resolved;
    }

    /**
     * Run a callback with a specific brand set as current, restoring the
     * previous current afterwards. For jobs, commands, and tests.
     */
    public function runFor(Brand|int|string $brand, Closure $callback): mixed
    {
        $previous = $this->current;

        try {
            $this->setCurrent($brand);

            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    /**
     * Run a callback with the brand global scope disabled. For legitimate
     * cross-brand admin/reporting operations only.
     */
    public function withoutBrandScope(Closure $callback): mixed
    {
        $previous = $this->scopeDisabled;
        $this->scopeDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopeDisabled = $previous;
        }
    }

    public function scopeIsDisabled(): bool
    {
        return $this->scopeDisabled;
    }

    /** Fail mode for the global scope when multi-brand is on but no current brand. */
    public function failMode(): string
    {
        return config('brand-context.fail_mode', 'closed');
    }
}
