<?php

namespace Goldnead\BrandContext\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that isolates branded models by the current brand.
 *
 * Single-brand mode: no-op — behaves exactly like an un-scoped model, so the
 * package is invisible to normal single-brand Statamic installs.
 *
 * Multi-brand mode: filters by the current brand id. If no current brand is
 * resolved, the configured fail mode decides — 'closed' (default) returns no
 * rows so nothing leaks across brands.
 */
class BrandScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $manager = app('brand-context');

        // Explicitly bypassed for legitimate cross-brand admin operations.
        if ($manager->scopeIsDisabled()) {
            return;
        }

        // Single-brand: everything is one brand; no filtering needed.
        if (! $manager->multiBrandEnabled()) {
            return;
        }

        $column = $model->getTable().'.'.$model->getBrandColumn();

        if (! $manager->hasCurrent()) {
            if ($manager->failMode() === 'open') {
                return;
            }

            // Fail closed: no current brand -> no rows.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $manager->currentId());
    }
}
