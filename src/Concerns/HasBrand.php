<?php

namespace Goldnead\BrandContext\Concerns;

use Goldnead\BrandContext\Exceptions\NoCurrentBrand;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Scopes\BrandScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Add to any Eloquent model that must be brand-scoped.
 *
 * Requires a `brand_id` column (override getBrandColumn() to change).
 * - Applies the BrandScope global scope (a no-op in single-brand mode).
 * - Stamps new records with the current brand id on create.
 */
trait HasBrand
{
    public static function bootHasBrand(): void
    {
        static::addGlobalScope(new BrandScope);

        static::creating(function ($model) {
            $column = $model->getBrandColumn();

            if (empty($model->{$column})) {
                $manager = app('brand-context');

                // The scope hides everything without a current brand; writing
                // into the default brand then would put the row where nobody
                // looking at the right brand can see it. Not when the scope is
                // bypassed on purpose (admin operation) or the operator chose
                // `fail_mode: open`. Single-brand always has a current brand.
                if (
                    ! $manager->hasCurrent()
                    && ! $manager->scopeIsDisabled()
                    && $manager->failMode() !== 'open'
                ) {
                    throw NoCurrentBrand::forModel($model::class);
                }

                // current() resolves to the default brand in single-brand mode
                // and to the active brand in multi-brand mode.
                $model->{$column} = $manager->currentId();
            }
        });
    }

    public function getBrandColumn(): string
    {
        return 'brand_id';
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, $this->getBrandColumn());
    }

    /** Query helper: bypass the brand scope for this query. */
    public function scopeAcrossBrands($query)
    {
        return $query->withoutGlobalScope(BrandScope::class);
    }

    /** Query helper: force a specific brand regardless of current context. */
    public function scopeForBrand($query, Brand|int $brand)
    {
        $id = $brand instanceof Brand ? $brand->id : $brand;

        return $query
            ->withoutGlobalScope(BrandScope::class)
            ->where($this->getTable().'.'.$this->getBrandColumn(), $id);
    }
}
