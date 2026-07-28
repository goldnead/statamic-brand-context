<?php

namespace Goldnead\BrandContext\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row: this user belongs to this brand.
 *
 * **This model deliberately does NOT use `HasBrand`.**
 *
 * It has a `brand_id`, so the trait would apply cleanly and look right — which
 * is exactly why the omission is written down here rather than left to be
 * rediscovered. Two reasons:
 *
 * 1. *It is the boundary, not something inside it.* The scope answers "which
 *    records may the current brand see". Membership rows answer "who is the
 *    current brand", and "which brands does this user belong to" is a question
 *    that spans brands by definition — under the global scope it could only
 *    ever return the current brand, i.e. the wrong answer with no error.
 * 2. *Fail-closed would break the very check it is meant to protect.* With
 *    multi-brand on and no current brand — a console run, a queue worker, a
 *    scheduled notification — the scope returns no rows. A membership lookup
 *    would then report "not assigned anywhere", which the transition rule
 *    turns into "member of every brand". Ambient scoping would silently invert
 *    the answer in precisely the contexts that have no session.
 *
 * The isolation is therefore not ambient but explicit: every read in
 * {@see \Goldnead\BrandContext\BrandMembership} names the brand id it means, so
 * the boundary is in the query rather than in the request state. Tests pin both
 * halves — that the scope is absent, and that a membership of one brand is
 * neither visible nor effective in another.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $user_id
 */
class BrandUser extends Model
{
    protected $table = 'brand_user';

    protected $guarded = [];

    protected $casts = [
        'brand_id' => 'integer',
        'user_id' => 'string',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
