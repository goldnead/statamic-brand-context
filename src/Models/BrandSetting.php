<?php

namespace Goldnead\BrandContext\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * One setting an operator changed from the Control Panel, for one brand.
 *
 * **Brand-scoped, unlike the three per-addon tables this replaces.** That is
 * the whole point of the layer: `automation_settings`, `leadhub_settings` and
 * `webhook_settings` each carry a bare `key`/`value` pair with no brand
 * column, so on a multi-brand install two brands silently share one setting.
 * {@see HasBrand} stamps the current brand on create and scopes every read, so
 * a screen opened under brand A cannot read or overwrite brand B's value even
 * if the payload names it.
 *
 * Reading across brands is possible on purpose but never implicit — it takes
 * `acrossBrands()`, and the only legitimate caller is a data migration that
 * has to see every row.
 *
 * @property int $brand_id
 * @property string $namespace
 * @property string $key
 * @property mixed $value
 */
class BrandSetting extends Model
{
    use HasBrand;

    protected $table = 'brand_settings';

    protected $fillable = ['brand_id', 'namespace', 'key', 'value'];

    protected $casts = [
        // `json`, not `array`. The values here are not all lists: a boolean
        // stored through the `array` cast comes back wrapped in one, and null
        // — a real value, meaning "same as the packaged default" — would not
        // survive the round trip at all.
        'value' => 'json',
    ];
}
