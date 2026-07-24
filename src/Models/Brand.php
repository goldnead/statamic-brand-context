<?php

namespace Goldnead\BrandContext\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A brand (tenant). Not itself brand-scoped — brands are the scoping root.
 *
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property bool $is_default
 * @property array|null $settings
 */
class Brand extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'settings' => 'array',
    ];

    public static function default(): ?self
    {
        return static::query()
            ->where('handle', config('brand-context.default_handle', 'default'))
            ->first()
            ?? static::query()->where('is_default', true)->first();
    }

    protected static function newFactory()
    {
        return \Goldnead\BrandContext\Database\Factories\BrandFactory::new();
    }
}
