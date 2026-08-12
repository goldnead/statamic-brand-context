# Brand Context

Optional multi-brand (multi-tenant) foundation for Statamic addons.

**Single-brand by default.** Most Statamic installs use the dependent addons exactly as before — one brand, no switcher, no visible machinery. The global scope is a no-op and every record belongs to a single default brand.

**Multi-brand behind a flag.** Flip `brand-context.multi_brand` on (optionally gated behind a license via `license_check`, so multi-brand can ship as a premium feature) to get hard brand isolation: the global scope filters every branded model by the current brand, new records are stamped with it, and the Control-Panel brand switcher appears.

The database schema is identical in both modes (`brand_id` everywhere, backfilled to the default brand), so enabling multi-brand later needs no migration.

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Laravel | 12.40 or newer, or 13 — the range `statamic/cms` ^6.0 itself allows |
| Statamic | 6.0 or newer |
| Database | Any Laravel-supported driver. The migrations are verified against MySQL 8 in CI; SQLite has no InnoDB key-length limit and is not a substitute for that run. |

The Control-Panel surface (brand switcher, Brand Members screen) needs Statamic. The rest of the
package boots in a plain Laravel application without it.

## Installation

```bash
composer require goldnead/statamic-brand-context
php artisan migrate
```

That is the whole install for single-brand mode. The default brand is created by the migration and
the global scope is a no-op, so nothing changes visibly.

To enable multi-brand, publish the config and flip the flag:

```bash
php artisan vendor:publish --tag=brand-context-config
```

```php
// config/brand-context.php
'multi_brand' => true,
```

**The flag is read once at boot.** Changing it takes effect on the next request, and on a deployed
site you need `php artisan config:clear` if the config is cached.

The Control-Panel assets are published automatically by Statamic. If you have disabled that, or the
switcher does not appear after an upgrade:

```bash
php artisan vendor:publish --tag=brand-context-cp --force
```

### Publish tags

| Tag | What it publishes |
|---|---|
| `brand-context-config` | `config/brand-context.php` |
| `brand-context-migrations` | The `brands` and `brand_user` migrations, if you want to edit them |
| `brand-context-cp` | The built Control-Panel bundle |
| `brand-context-translations` | `lang/vendor/brand-context/{en,de}` |

### Building the Control-Panel assets

Only needed when working on this package itself. `@statamic/cms` resolves from the installed vendor
directory, so Composer must run first:

```bash
composer install
npm install
npm run build      # writes resources/dist/build, which is committed
```

## Concepts

- **`Brand` model** — the tenant. Not itself scoped; brands are the scoping root. A default brand always exists.
- **`BrandMembers` facade / `BrandMembership`** — which Control Panel users belong to which brand. The one part of this package that is not about Eloquent models. See [Brand members](#brand-members-which-cp-users-belong-to-a-brand).
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

## Brand members (which CP users belong to a brand)

The global scope isolates **Eloquent models**. A Statamic user is not one — with
the file users repository it is not a database row at all — so "the users of this
brand" cannot be expressed by scoping and gets its own answer:

```php
use Goldnead\BrandContext\Facades\BrandMembers;

BrandMembers::usersOf();            // Statamic users of the current brand
BrandMembers::usersOf('acme');      // …of a named brand
BrandMembers::includes($user);      // does this user belong to the current brand?
BrandMembers::brandsOf($user);      // which brands does this user belong to?
```

Membership is brand affiliation, never authorisation. Consumers combine it with
their own permission check:

```php
$assignees = BrandMembers::usersOf()
    ->filter(fn ($user) => $user->can('view leadhub'))
    ->map(fn ($user) => ['value' => (string) $user->id(), 'label' => $user->email()]);
```

Write access is `attach()` / `detach()`, both idempotent and both taking the same
brand argument. The Control Panel screen for it lives under **Users → Brand
Members** and always acts on the brand in the switcher; it appears only in
multi-brand mode.

### The rule that will surprise you

> **A user with no membership at all counts as a member of every brand.**

Every install upgrading into this feature starts with an empty `brand_user`
table. Strict filtering would empty every assignee dropdown, every team
notification and every approval list on the day of the upgrade — and it would
look exactly like a permissions bug. So nothing changes until somebody
deliberately assigns a user. **The first assignment is what narrows that user
down**, and it narrows them everywhere at once: from then on they belong only to
the brands listed for them. Removing their last assignment puts them back into
every brand; there is deliberately no way to express "member of nothing", which
is what revoking a permission is for.

`includes()`, `usersOf()`, `filter()` and `brandsOf()` apply the rule.
`assignedUserIdsOf()` and `assignedBrandIdsOf()` return the raw rows and do
**not** — they are for rendering and auditing the assignments themselves, never
for deciding who may be offered, notified or assigned.

### Notes for consumers

- **A user id is a string.** `brand_user.user_id` holds `$user->id()`, which is a
  uuid under the file driver and a numeric key under the eloquent one. There is
  no foreign key on it — a Statamic install need not have a `users` table at all.
  `attach()` also accepts a Statamic user, an `Authenticatable`, an Eloquent
  model or an `Identity` from `goldnead/statamic-identity-contracts`.
- **Name the brand in code without a session.** With multi-brand on and no
  current brand (a console command, a queue worker), the membership API refuses
  to guess and throws. Pass the brand, or wrap the work in
  `BrandContext::runFor()` / the `RunsForEachBrand` trait.
- **Single-brand installs are unaffected**: `includes()` is always true and
  `usersOf()` returns every user.

## Public routes

Links in an e-mail are opened without a session, so no brand is current and the
fail-closed scope hides the record the link points at. The brand comes from the
token instead:

```php
use Goldnead\BrandContext\Http\Middleware\SetBrandFromRouteValue;

Route::get('/confirm/{token}', ConfirmController::class)
    ->middleware(SetBrandFromRouteValue::class.':'.Subscription::class.',token,token');
```

The three arguments are the model, the column to look the value up in, and the
route parameter (or input field) carrying it.

**The column must carry a unique index across all brands.** One token, one
record, one brand — that is the whole safety argument. If two records answer,
`brandForUnique()` throws `AmbiguousBrandRecord` rather than guessing, because
guessing means serving one brand's record to another brand's visitor. Never pass
a column that is unique only per brand.

Nothing is aborted by the middleware: an unknown value sets no brand, the scope
stays closed, and the controller produces the response it always produced. And
the brand is set explicitly on every request — never inherited from the last one,
which matters the moment the app runs in a long-lived process.

## Sender identity (who a brand's mail goes out as)

A mail belongs to a brand, so the address it comes from and the transport it
leaves through belong to the brand too. Both live in `brands.settings.mail`:

```php
$brand->update(['settings' => ['mail' => [
    'from_address' => 'noreply@chorgesucht.de',
    'from_name'    => 'chorgesucht.de',        // defaults to the brand name
    'mailer'       => 'scaleway_chorgesucht',  // a mailer from config/mail.php
    'locale'       => 'de',                    // the language its mail is written in
]]]);
```

**Why the transport and not just the From.** A relay that verifies sending
domains per account (Scaleway TEM, Postmark, SES) refuses — or silently replaces
— a From it does not own. Sending brand A's mail through the account that only
knows brand B is how a reader ends up with brand A's newsletter under brand B's
name. The two values have to be chosen together, which is why they live in one
place and are resolved in one place. The SMTP credentials stay in the
environment and never reach the database, a backup or a CP export.

Sending goes through `BrandMailer`, which puts the identity **on the message**
and never into the config:

```php
app(BrandMailer::class)->send($brandId, $to, $toName, $mailable);
app(BrandMailer::class)->sendRaw($brandId, $html, $text, fn ($message, $identity) => …);
app(BrandMailer::class)->maySend($brandId);   // ask before you stamp anything
```

Four rules, and the reasons they are rules:

- **A brand with no `settings.mail` changes nothing** — the configured
  transport, whatever From the mailable settles on, the app locale. That is
  every single-brand install. Under multi-brand it also writes one warning per
  brand per five minutes, because there the host-wide From *is* somebody else's
  identity; refusing instead would make an install fall silent on an upgrade,
  which is the failure mode this whole thing is fighting.
- **A brand that declares `settings.mail` but no `from_address` sends nothing**,
  and says why. So does one naming a `mailer` that `config/mail.php` does not
  define — caught when the identity is resolved rather than at the send, because
  a digest stamps "delivered" on a week of items before the mail leaves.
- **`mail.from.*` is never written.** Laravel reads it the first time a mailer
  name is resolved and burns it into the cached instance (`alwaysFrom`), so an
  override escapes its own `finally`: whichever brand sent first leaves its
  address standing for every later message that sets no From of its own. That is
  not fixable by being careful with config.
- **A refusal is a return value, not an exception.** A run across several brands
  has to skip one and carry on.

A mailable that travels this way must not overwrite a From that is already
there:

```php
if (empty($this->from)) {
    $this->from(config('mail.from.address'), config('mail.from.name'));
}
```

A host that keeps sender identities somewhere else rebinds one contract:

```php
$this->app->bind(
    \Goldnead\BrandContext\Contracts\SenderIdentityResolver::class,
    MyOwnResolver::class,   // resolve(?int $brandId): SenderIdentity
);
```

Addons that send mail (`statamic-marketing`, `-notifications`,
`-preference-center`, `-automations`) extend that interface in their own
namespace and bind their own default, so a host can answer the question for
marketing post alone without touching transactional post. Rebinding the contract
above changes it for everything that has not been rebound individually.

## Testing

```bash
composer install
composer test          # Pest, SQLite
composer test:mysql    # the same suite against MySQL — needs a running server
composer lint:test     # Pint, check only
composer analyse       # PHPStan level 5, baselined
npm ci && npm test     # the two Vue components
```

CI runs all of it on every push, plus a job that rebuilds `resources/dist` and fails if the
committed bundle has drifted from its sources.

## Support

Only the latest version of this addon is supported, against the Statamic major it targets. Bugs and
questions go to [GitHub issues](https://github.com/goldnead/statamic-brand-context/issues); bugs in
Statamic itself belong in [statamic/cms](https://github.com/statamic/cms/issues).

Security reports do not go into a public issue — see [SECURITY.md](SECURITY.md).

## Changelog and license

[CHANGELOG.md](CHANGELOG.md) · MIT, see [LICENSE.md](LICENSE.md).
