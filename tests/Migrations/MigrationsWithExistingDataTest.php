<?php

use Goldnead\BrandContext\Tests\Fixtures\BrandContextDataFixture;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this package had ran against a table it had just
 * created itself, on an in-memory SQLite database, with nothing in it. That is
 * not a thin spot in the coverage; it is the coverage pointing away from the
 * only case a migration can be wrong about — a table with rows in it, created
 * by an older release.
 *
 * It matters more here than in any sibling. `brands` is the table every other
 * addon's `brand_id` migration reads: they backfill their rows onto whatever
 * `is_default` returns, and they constrain against ids that must still be
 * there. If a migration in this package ever loses a brand, renames one, or
 * lets two share a handle, the damage does not surface here — it surfaces in
 * four other addons, as rows attached to a brand that no longer means what they
 * thought it meant.
 *
 * This file names no migration. It walks `database/migrations/`, seeds a fresh
 * generation of brands and memberships into every table that already exists
 * before each file runs, and applies them one at a time. A migration added
 * years from now is covered the day it lands.
 *
 * Every assertion is behavioural. "The migration ran" and "the constraint is
 * there" are not the same statement, and neither is "an index named
 * `brand_user_unique` exists" the same as "this table cannot record the same
 * membership twice". An index can be present under the right name over the
 * wrong columns, or over a column a later migration made nullable — and a
 * unique constrains no NULLs on either engine, which is how the same defect
 * reached production in two siblings already. So nothing below checks an exit
 * code or an index name: it writes the row the constraint is supposed to refuse
 * and requires the database to refuse it.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new BrandContextDataFixture($this->isolated());
    $batch = 0;
    $seeded = 0;

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written under its own predecessor's schema is still only
    // being tested against a fresh install with a bit of data in it. The first
    // pass finds no `brands` table and writes nothing, which is correct — the
    // fixture asks the schema what exists rather than assuming.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch, &$seeded): void {
        $seeded += $fixture->seed($batch++);
    });

    // One more generation once the last migration has landed, so the table it
    // created is populated too. Without this the newest table would be the one
    // table no probe could reach.
    $seeded += $fixture->seed($batch++);

    expect($seeded)->toBeGreaterThan(0, 'the fixture never found a table to seed');

    // Nothing may have gone missing on the way, and nothing may have been
    // duplicated. The +1 is the default brand, which `create_brands_table`
    // inserts itself.
    expect(array_sum($fixture->counts()))->toBe($seeded + 1);

    // Handles, probed on brands that were already in the table when the last
    // migration ran.
    expect($this->duplicateBrandHandleIsAccepted(BrandContextDataFixture::handleProbe(1)))
        ->toBeFalse('brands.handle stopped being globally unique after a stepwise migration over populated tables');

    expect($this->duplicateBrandHandleIsAccepted(BrandContextDataFixture::DEFAULT_HANDLE))
        ->toBeFalse('a second brand could be created carrying the default handle');

    // Memberships.
    $probe = BrandContextDataFixture::membershipProbe($batch - 1);
    $brandId = $fixture->brandId($probe['brand'], $batch - 1);

    expect($this->duplicateMembershipIsAccepted($brandId, $probe['user']))
        ->toBeFalse('brand_user accepted a second row for a (brand_id, user_id) pair it already holds');

    // ...and it is a *pair* unique, not a unique over the user. A migration
    // that rebuilt it over `user_id` alone would pass the check above while
    // forbidding the one thing this table exists to express.
    $otherBrandId = $fixture->brandId(BrandContextDataFixture::membershipProbeOtherBrand(), $batch - 1);

    expect($this->sameUserInAnotherBrandIsAccepted($otherBrandId, $probe['user']))
        ->toBeTrue('a user can no longer belong to more than one brand');

    // The widest user id the table is contracted to keep came through whole.
    // `user_id` is capped at 191 rather than the default 255 so the unique it
    // sits in stays inside InnoDB's byte budget; a migration that narrowed it
    // further would quietly cut the file-driver uuids of long-lived installs.
    $widest = $this->isolated()->table('brand_user')
        ->pluck('user_id')
        ->map(fn (string $id): int => strlen($id))
        ->max();

    expect($widest)->toBe(
        BrandContextDataFixture::USER_ID_MAX,
        'the widest user id the fixture wrote did not survive at full length'
    );
});

/**
 * The released schemas, taken from the tags with `git show <tag>:<file>` and
 * kept verbatim under tests/Fixtures/released-migrations/.
 *
 * Two sets, because two is how many distinct shapes this package has shipped:
 * 1.4.0 is `brands` alone, which is every install from 1.0.0 up to and
 * including 1.4.0 — `database/migrations/` is byte-identical across all of
 * them — and 1.5.0 is the one that added `brand_user`. Adding another set is a
 * matter of dropping a directory in and naming it below.
 */
it('upgrades a populated install from every released schema', function (string $version): void {
    // The install as it stood on that release, with its data.
    $this->migratePath($this->releasedMigrations($version));

    $fixture = new BrandContextDataFixture($this->isolated());
    $seeded = $fixture->seed(0);

    expect($seeded)->toBeGreaterThan(0);

    $before = $fixture->counts();

    // Then the upgrade, with the tables filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch, &$seeded): void {
        $seeded += $fixture->seed($batch++);
    });

    $seeded += $fixture->seed($batch++);

    // Nothing that was there before may have gone missing.
    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect(array_sum($fixture->counts()))->toBe($seeded + 1);

    // The guarantees the siblings read this package for, on rows that were
    // written before the upgrade started.
    expect($this->duplicateBrandHandleIsAccepted(BrandContextDataFixture::handleProbe(0)))
        ->toBeFalse("brands.handle does not stay unique after upgrading a populated {$version} install");

    $probe = BrandContextDataFixture::membershipProbe($batch - 1);
    $brandId = $fixture->brandId($probe['brand'], $batch - 1);

    expect($this->duplicateMembershipIsAccepted($brandId, $probe['user']))
        ->toBeFalse("brand_user does not refuse a duplicate membership after upgrading a populated {$version} install");

    $otherBrandId = $fixture->brandId(BrandContextDataFixture::membershipProbeOtherBrand(), $batch - 1);

    expect($this->sameUserInAnotherBrandIsAccepted($otherBrandId, $probe['user']))
        ->toBeTrue('a user can no longer belong to more than one brand');
})->with(['v1.4.0', 'v1.5.0']);

it('leaves exactly one default brand behind, however many times it is migrated', function (): void {
    // `create_brands_table` inserts the default brand with `insertOrIgnore`, so
    // it is the one row in this package that a migration writes rather than a
    // caller. Every sibling's backfill points at it — `where('is_default', true)`
    // — and a second one would send half of another addon's rows to a brand
    // nobody selected.
    $this->migratePath($this->releasedMigrations('v1.4.0'));

    $fixture = new BrandContextDataFixture($this->isolated());
    $fixture->seed(0);

    $defaultId = $fixture->brandId(BrandContextDataFixture::DEFAULT_HANDLE);

    expect($defaultId)->not->toBeNull();

    $this->migratePath($this->currentMigrations());

    // Same row, same id: not replaced, not re-inserted, not joined by a second.
    expect($fixture->brandId(BrandContextDataFixture::DEFAULT_HANDLE))->toBe($defaultId);
    expect($this->isolated()->table('brands')->where('is_default', true)->count())->toBe(1);

    // And the schema still refuses to let anything else take that handle,
    // which is the only reason `insertOrIgnore` is safe in the first place.
    expect($this->duplicateBrandHandleIsAccepted(BrandContextDataFixture::DEFAULT_HANDLE))->toBeFalse();
});
