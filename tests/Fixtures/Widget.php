<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    use HasBrand;

    protected $guarded = [];
}
