# Changelog

## 1.4.0 — 2026-07-27

### Added — deriving the brand from a public token

The third and last execution class that never passed through `SetBrandFromSession`: confirmation links, unsubscribe links (including RFC 8058 one-click, which mail providers call themselves) and open/click tracking. A mail client arrives with no session, so no brand is current, the fail-closed scope hides the very record the token points at, and the link 404s. Tracking is the quiet one — the pixel returns 200 and stores nothing, so campaign statistics sit at 0 % forever and nothing looks broken.

The brand cannot come from the session here, so it comes from the token:

```php
Route::get('/confirm/{token}', ConfirmController::class)
    ->middleware(SetBrandFromRouteValue::class.':'.Subscription::class.',token,token');
```

Or directly:

```php
$brand = BrandContext::brandForUnique(Subscription::class, 'token', $token);
```

Three properties make this a fix rather than a hole in the isolation:

**The column must be unique across all brands.** That is the entire safety argument: one token, one record, one brand. When two records answer, `brandForUnique()` throws `AmbiguousBrandRecord` instead of picking one, because picking one means serving brand A's visitor a record of brand B. A column that is unique only *per brand* must never be passed.

**The negative path is untouched.** No record, no brand, no abort — the scope stays closed and the controller returns exactly what it returned before. An unknown token and another brand's token are indistinguishable, because both do nothing.

**The brand is always set explicitly, never inherited.** The manager is a singleton. In a long-lived process (Octane, a worker serving requests) a request with an unknown token would otherwise run under the previous visitor's brand. The middleware therefore forgets as deliberately as it sets. This was found by a test, not by reasoning — the first implementation leaked.

Single-brand installs pass straight through, as always.

## 1.3.0 — 2026-07-27

### Added — `RunsForEachBrand` for artisan commands

A console run has no session, so no brand is current. With multi-brand on, the global scope then fails closed and every query returns nothing — a scheduled command reports "0 processed" and looks perfectly healthy while doing nothing at all.

That failure mode is silent, survives indefinitely, and the hub QA run found it in four separate commands across three addons (digests, automation delays, webhook retries, webhook health). The trait supplies one implementation instead of four:

```php
use RunsForEachBrand;

protected $signature = 'automations:run-due {--brand= : Restrict to one brand}';

public function handle(): int
{
    return $this->forEachBrand(fn () => $this->process());
}
```

Single-brand installs are unaffected — the callback runs once, in the ambient context, with no brand switching at all.

### Notes

- Suite: **24 passed (42 assertions)**, including the scheduler case (no current brand, every brand walked) and the `--brand` restriction.

## 1.2.2 — 2026-07-27

### Fixed — the brand was resolved after route-model binding, so bound routes 404'd

- **`SetBrandFromSession` ran at the end of the `statamic.cp` stack, behind `SubstituteBindings`.** Route-model binding resolves `{webhook}`, `{delivery}`, `{automation}` and friends through the query builder; with no brand current at that moment the fail-closed scope hid the record, the lookup found nothing and the request died as a 404. Every edit, delete, toggle and detail page in every addon with bound models was unreachable under multi-brand.
- `pushMiddlewareToGroup` always appends, which is how it ended up there. The group is now rebuilt with the middleware spliced in directly before `SubstituteBindings`, falling back to appending when that middleware is absent.
- **The isolation is unchanged**, and that was verified rather than assumed: with the owning brand active the bound route answers 200, and the same record requested under a different brand still answers 404.

### Also in this release

- **`loadViewsFrom` no longer registers a directory that does not exist** (from 2026-07-25, previously untagged). The addon ships no Blade views, so the stale registration made a consumer's `php artisan view:cache` — part of a deploy `optimize` — fail with "directory does not exist".

### Notes

- The binding fix was found in the hub QA run, where two addons independently reported every detail page as 404.
- Suite: **20 passed (33 assertions)**.
