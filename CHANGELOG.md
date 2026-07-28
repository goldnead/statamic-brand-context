# Changelog

## 1.5.1 — 2026-07-28

### Added — the suite can finally be run against MySQL

This package was the last one in the family with no way to run its tests against the engine its users run. That is the wrong way round. It owns `brands` — the table every sibling's `brand_id` migration reads, backfills from and constrains against — and `brand_user`, whose unique spans a column narrowed to 191 characters for byte-budget reasons SQLite cannot express at all. The foundation of the family was the one part of it never measured on the real thing.

`phpunit.mysql.xml` runs the identical suite against a real server: `DB_DRIVER=mysql`, with the usual `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`. `tests/TestCase::testingConnection()` is the switch, matching the siblings exactly. The default is unchanged in every respect — in-memory SQLite, no setup, nothing to install in CI.

### Added — the migrations are finally tested against a database with data in it

The same sweep that produced the file above, prompted by `statamic-marketing` 1.6.4, looked across all eight addons for a check that runs a migration against tables that already hold rows. It found none. Every migration in every addon had only ever met tables the test created moments earlier, which is the one shape a migration can never be wrong about.

It matters more here than in any sibling, and not because of what could break in this package. `brands` is where four other addons read their tenant from: they backfill their rows onto whatever `is_default` returns and constrain against ids that have to still be there. A migration here that loses a brand, renames one, or lets two share a handle does not show up as a failure in this package. It shows up in four others, as rows attached to a brand that no longer means what they were attached to.

`tests/Migrations/` names no migration. It walks `database/migrations/`, seeds a fresh generation of brands and memberships into every table that already exists before each file runs, and applies them one at a time — so a migration added years from now is covered the day it lands. `tests/Fixtures/released-migrations/` holds the sets as published in 1.4.0 (`brands` alone) and 1.5.0, and the suite installs each, fills it and upgrades forward.

Every check is behavioural. "The migration ran" and "the constraint is there" are not the same statement, and neither is "an index named `brand_user_unique` exists" the same as "this table cannot record the same membership twice" — a unique constrains no NULL on either engine, which is how the same defect has now reached production in two siblings. So nothing here asserts an exit code or an index name. It writes the row the constraint is supposed to refuse and requires the database to refuse it, and it writes the counterpart too: the same user in a *different* brand must still be accepted, which is what a unique rebuilt over `user_id` alone would break while passing everything else.

One case pins something this package has always relied on without ever checking: `create_brands_table` inserts the default brand with `insertOrIgnore`, so re-migrating a populated database must leave that brand with the same id, exactly one row carrying `is_default`, and the handle still unclaimable. Every sibling's backfill resolves its tenant through those three facts.

### Notes

- Suite: **66 passed (170 assertions)** on SQLite, baseline 62. Green against MySQL 8.0 as well, through `phpunit.mysql.xml`, including the new `Migrations` suite.
- `phpunit.xml` gained the `<php>` block the siblings carry (`APP_ENV`, `DB_CONNECTION=testing`, `QUEUE_CONNECTION=sync`) so a developer's real `.env` cannot reach the suite. It was already all three; now it says so.

## 1.5.0 — 2026-07-28

### Added — brand membership for Control Panel users

Everything this package isolates so far is an Eloquent model, reached through
`HasBrand` and a global scope. A Statamic user is not one of those. There is no
`brand_id` on it, no membership table, no role per brand — and with the file
users repository it is not a database row at all, so there is nothing for a
scope to filter.

That wall was hit while building task assignment in LeadHub 1.7.0. The decision
there was "assignees are the CP users of the respective brand", and it could not
be built: what shipped is a list of everyone who may see LeadHub, across all
brands. The work itself is isolated — tasks, filters and "my tasks" do not cross
the tenant boundary, and that is asserted — but the list of names is too wide.
The next addon to ask "who from this brand" for a team notification or an
approval would have hit exactly the same wall, which is why the answer belongs
here rather than in any one addon.

**A user may belong to several brands**, so the assignment lives in its own
table (`brand_user`) rather than in a column on the user. The single-brand case
is one row; the reverse modelling could not express the other case without a
later migration.

**The user side carries no foreign key.** `user_id` is a `varchar(191)` holding
`$user->id()` — a uuid under the file driver, a numeric key under the eloquent
one. A foreign key to `users` would make the addon uninstallable on exactly the
installs Statamic ships by default. The id is the one thing both drivers agree
on, and it is the same key `goldnead/statamic-identity-contracts` already
stringifies as `Identity::$userId`; `attach()` accepts an `Identity` for that
reason. Everything above the table goes through `Statamic\Facades\User`, so
neither this package nor its consumers ever learn which driver an install uses.

#### How a consumer uses it

```php
use Goldnead\BrandContext\Facades\BrandMembers;

$assignees = BrandMembers::usersOf()                     // current brand
    ->filter(fn ($user) => $user->can('view leadhub'))   // your permission
    ->map(fn ($user) => ['value' => (string) $user->id(), 'label' => $user->email()]);
```

Membership is brand affiliation, never authorisation: the permission check stays
in the consuming addon, the brand affiliation comes from here. The reverse
question is `BrandMembers::brandsOf($user)`, the predicate is
`BrandMembers::includes($user, $brand)`, and writes are `attach()` / `detach()`.

#### One rule that had to be surprising

**A user with no membership at all counts as a member of every brand.**

Every install upgrading into this feature starts with an empty table. Filtering
strictly would empty every assignee dropdown, every team notification and every
approval list on the day of the upgrade — and it would look like a permissions
bug, not like a feature: the names are gone, nobody knows why, and the fix is
invisible. So nothing changes until somebody deliberately assigns a user. The
*first* assignment for a user is what narrows them down, and it narrows them
everywhere at once. Removing their last assignment puts them back everywhere;
there is deliberately no way to say "member of nothing", which is what revoking
a permission is for.

Because a rule like that decays in one direction — "counts everywhere" quietly
becoming "may do anything" — it is stated where it is read rather than only
here: in the README, in the PHPDoc of the class a consumer calls, and on the
Control Panel screen itself. `BrandMembershipTransitionTest` pins each of its
edges, including the one that matters most: an unassigned user still cannot see
another brand's **records**. The rule is about people, never about data, and the
fail-closed scope is untouched by it.

The raw rows are reachable through `assignedUserIdsOf()` / `assignedBrandIdsOf()`,
which deliberately do *not* apply the rule and are named so they cannot be
mistaken for the member list.

#### Where the boundary is, and where it deliberately is not

`brand_user` is the only table in this package with a `brand_id` that does **not**
use `HasBrand`, and that omission is the considered part of the design. Under
the global scope, "which brands does this user belong to" could only ever return
the current brand — the wrong answer with no error — and in a console run or a
queue worker, where no brand is current, the fail-closed scope would read zero
membership rows and the transition rule would turn that into "everybody belongs
everywhere". Ambient scoping would invert the boundary in precisely the contexts
that have no session to notice.

So the isolation is explicit instead of ambient: every read names the brand id it
means. For the same reason the API refuses to guess — with multi-brand on and no
current brand it throws rather than falling back to the default brand, because
falling back would answer a question about brand B with brand A's memberships.

That this holds is a test, not a screenshot: a membership of one brand is neither
visible nor effective in another, deleting from one brand leaves the other's row
alone, and the Control Panel endpoint writes to the current brand while ignoring
any brand the request names.

#### Control Panel

**Users → Brand Members**, visible only in multi-brand mode (a single-brand
install has one brand and everybody is in it). Assign and remove per user; the
screen always acts on the brand in the switcher, which is why it has no brand
field to tamper with. Rejections are shown — inline at the row and in a summary
above it — following the pattern marketing 1.5.3 established, so a refused
action never reads as a dead button. Guarded by a new `manage brand members`
permission.

### Also in this release

- **`tests/Unit/IndexKeyLengthTest.php`**, adopted from notifications 1.0.4 —
  it compiles the addon's own migrations through Laravel's MySQL grammar in
  pretend mode and measures every index without a server. brand-context acquired
  the same exposure the moment it gained a unique over a string user id: under
  utf8mb4 a `varchar(255)` costs 1020 bytes in an index and InnoDB allows 3072,
  and SQLite has none of that arithmetic to fail on. `user_id` is capped at 191
  for that reason — 772 bytes for the whole key, with room to spare.
- The same test asserts that both columns of `brand_user_unique` are NOT NULL. A
  unique index does not constrain NULLs, so a nullable `user_id` would let the
  index sit there enforcing nothing — a defect that has already shipped twice in
  this family (notifications, automations). Here the unique *is* the membership,
  so it would have permitted unlimited duplicates.

### Notes

- Suite: **62 passed (143 assertions)**, up from 32.
- Not included on purpose: the consumption side in LeadHub. This release ends at
  the API.

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
