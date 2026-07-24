# Brand Context

Optional multi-brand (multi-tenant) foundation for Statamic addons.

**Single-brand by default.** Most Statamic installs use the dependent addons exactly as before — one brand, no switcher, no visible machinery. The global scope is a no-op and every record belongs to a single default brand.

**Multi-brand behind a flag.** Flip `brand-context.multi_brand` on (optionally gated behind a license via `license_check`, so multi-brand can ship as a premium feature) to get hard brand isolation: the global scope filters every branded model by the current brand, new records are stamped with it, and the Control-Panel brand switcher appears.

The database schema is identical in both modes (`brand_id` everywhere, backfilled to the default brand), so enabling multi-brand later needs no migration.

## Concepts

- **`Brand` model** — the tenant. Not itself scoped; brands are the scoping root. A default brand always exists.
- **`HasBrand` trait** — add to any Eloquent model that must be brand-scoped. Applies the global `BrandScope` and stamps `brand_id` on create.
- **`BrandContext` facade / `BrandManager`** — `multiBrandEnabled()`, `current()`, `setCurrent()`, `runFor()`, `withoutBrandScope()`.
- **`ResolveBrandFromToken` middleware** (`brand.token`) — API paths: resolves the Bearer token to a brand, fail-closed (401) in multi-brand mode.
- **`SetBrandFromSession` middleware** (`brand.session`) — CP paths: reads the brand the switcher stored.

## Isolation guarantees (multi-brand mode)

- A query on a `HasBrand` model only ever returns the current brand's rows.
- With no current brand resolved and `fail_mode=closed` (default), reads return **no** rows — nothing leaks across brands. Explicit cross-brand access is opt-in via `BrandContext::withoutBrandScope()`.
- **Consent is per brand.** The same email can hold independent consent/subscription state in different brands; uniqueness is enforced as `(brand_id, …)`.

## Usage

```php
use Goldnead\BrandContext\Concerns\HasBrand;

class Contact extends Model
{
    use HasBrand; // requires a brand_id column
}
```

```php
BrandContext::runFor('acme', function () {
    Contact::create([...]); // stamped with the acme brand, isolated from others
});
```

## Testing

```bash
composer install
composer test
```
