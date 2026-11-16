<?php

namespace Goldnead\BrandContext\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** A model whose table was never migrated: the shape of an addon on an unfinished install. */
class Ghost extends Model
{
    protected $table = 'ghosts_never_migrated';
}
