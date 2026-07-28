<?php

namespace Goldnead\BrandContext\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bed for migrating a database by hand, from any released schema forward.
 *
 * The rest of the suite runs against a database that `RefreshDatabase` has
 * already migrated to head, which is the one shape a migration can never be
 * wrong about. Everything here needs the opposite: an empty database, an
 * arbitrary earlier release installed into it, rows put in, and then the
 * migrations run one at a time with the tables no longer empty.
 *
 * That cannot share the suite's connection. `RefreshDatabase` wraps every test
 * in a transaction, and DDL under MySQL commits implicitly — a `migrate` run
 * inside that transaction would end it and leak its tables into every test
 * that followed. So these tests get a connection of their own, outside
 * anything the trait manages: a temp-file SQLite database by default, and a
 * second throwaway schema beside the configured one when the suite is pointed
 * at MySQL (see phpunit.mysql.xml). It is torn down between tests either way.
 *
 * Unlike its counterparts in the sibling addons, `resetIsolatedDatabase()`
 * installs nothing before this package's own migrations. There is nothing to
 * install: `brands` is the table every sibling's `brand_id` migration reads and
 * backfills from, so this package is the precondition rather than something
 * with one. An empty database is the correct starting point, and the only one.
 */
abstract class MigrationPathTestCase extends TestCase
{
    /**
     * The name of the isolated connection these tests migrate.
     */
    protected const CONNECTION = 'migration_path';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropIsolatedSqliteFile();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.'.self::CONNECTION, $this->isolatedConnection());

        // A server-level handle with no database selected, used for nothing but
        // `create database`. Issuing that on the suite's own connection would
        // implicitly commit the transaction RefreshDatabase is holding open,
        // and every test after this one would roll back into nothing.
        $app['config']->set('database.connections.'.self::CONNECTION.'_server', [
            ...$this->isolatedConnection(),
            'database' => null,
        ]);
    }

    /**
     * Mirrors TestCase::testingConnection(), so these tests exercise the same
     * engine the rest of the run does — including the MySQL run, where the
     * foreign key on `brand_user.brand_id` and the index rules that SQLite does
     * not enforce are the whole point.
     */
    protected function isolatedConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => $this->isolatedSqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => $this->isolatedDatabaseName(),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function isolatedDatabaseName(): string
    {
        return env('DB_DATABASE', 'brand_context_test').'_migration_path';
    }

    protected function isolatedSqlitePath(): string
    {
        return sys_get_temp_dir().'/brand-context-migration-path-'.getmypid().'.sqlite';
    }

    /**
     * An empty database, with nothing of this package's own in it.
     */
    protected function resetIsolatedDatabase(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            $this->dropIsolatedSqliteFile();
            touch($this->isolatedSqlitePath());
        } else {
            DB::connection(self::CONNECTION.'_server')->statement(
                'create database if not exists `'.$this->isolatedDatabaseName().'` character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            DB::purge(self::CONNECTION.'_server');
        }

        DB::purge(self::CONNECTION);

        // `brand_user` holds a foreign key into `brands`, so the order tables
        // are dropped in matters. Both schema builders switch constraint
        // checking off around this for exactly that reason.
        Schema::connection(self::CONNECTION)->dropAllTables();

        DB::purge(self::CONNECTION);
    }

    protected function dropIsolatedSqliteFile(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            DB::purge(self::CONNECTION);

            if (file_exists($this->isolatedSqlitePath())) {
                @unlink($this->isolatedSqlitePath());
            }
        }
    }

    /**
     * Run every not-yet-run migration in a directory against the isolated
     * connection. Failures are not swallowed: the point of these tests is what
     * happens when one throws.
     */
    protected function migratePath(string $path): void
    {
        // `Migrator::setConnection()` makes the migrated connection the default
        // one for the duration, which is what lets a migration written with the
        // bare `DB::` and `Schema::` facades reach the isolated database at all
        // — `create_brands_table` inserts the default brand through exactly
        // that. It does not put the default back afterwards, so this does:
        // leaving it pointed at `migration_path` would send RefreshDatabase's
        // rollback to a connection it never opened a transaction on.
        $default = DB::getDefaultConnection();

        try {
            Artisan::call('migrate', [
                '--database' => self::CONNECTION,
                '--path' => $path,
                '--realpath' => true,
                '--force' => true,
            ]);
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /**
     * Run the migrations in a directory one file at a time, handing control
     * back between each so a caller can put rows in first.
     *
     * @param  callable(string): void|null  $before  receives the migration name
     */
    protected function migrateStepwise(string $path, ?callable $before = null): void
    {
        foreach ($this->migrationFilesIn($path) as $file) {
            if ($before) {
                $before(basename($file, '.php'));
            }

            $this->migratePath($file);
        }
    }

    /**
     * @return list<string>
     */
    protected function migrationFilesIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];

        sort($files);

        return $files;
    }

    protected function releasedMigrations(string $version): string
    {
        return __DIR__.'/Fixtures/released-migrations/'.$version;
    }

    protected function currentMigrations(): string
    {
        return __DIR__.'/../database/migrations';
    }

    protected function isolated(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    protected function isolatedSchema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection(self::CONNECTION);
    }

    /**
     * The migration names the isolated database has recorded as run.
     *
     * @return list<string>
     */
    protected function ranMigrations(): array
    {
        if (! $this->isolatedSchema()->hasTable('migrations')) {
            return [];
        }

        return $this->isolated()->table('migrations')->pluck('migration')->all();
    }

    /**
     * Whether a second brand can be created carrying a handle that is already
     * taken.
     *
     * The handle is what every sibling resolves a brand by — a route segment, a
     * config value, a queued job's payload — so it has to be globally unique or
     * that resolution silently picks whichever row came back first. This check
     * is deliberately behavioural. "The migration ran" and "the constraint is
     * there" are not the same statement, and neither is "an index named
     * `brands_handle_unique` exists" the same as "two brands cannot share a
     * handle". An index can be present under the right name over the wrong
     * column, or over a column a later migration made nullable, and enforce
     * nothing at all. The only thing that settles it is writing the row the
     * constraint is supposed to refuse.
     */
    protected function duplicateBrandHandleIsAccepted(string $handle): bool
    {
        $existing = $this->isolated()->table('brands')->where('handle', $handle)->first();

        if (! $existing) {
            throw new \RuntimeException("No brand with the handle [{$handle}] to duplicate.");
        }

        $row = collect((array) $existing)
            ->except('id')
            ->put('is_default', false)
            ->all();

        return $this->insertIsAccepted('brands', $row);
    }

    /**
     * Whether the same user can be recorded twice as a member of the same
     * brand.
     *
     * `brand_user_unique` over `(brand_id, user_id)` is the whole content of
     * that table: a membership is a fact that either holds or does not, and a
     * second row for the same pair turns every membership check into a question
     * about how many duplicates happen to exist. Both columns are NOT NULL
     * precisely so that this unique constrains the rows that matter — a unique
     * does not constrain NULLs on either engine.
     */
    protected function duplicateMembershipIsAccepted(int $brandId, string $userId): bool
    {
        return $this->insertIsAccepted('brand_user', $this->membershipRow($brandId, $userId));
    }

    /**
     * The counterpart, and the reason the check above is not enough on its own.
     *
     * A migration that rebuilt the unique over `user_id` alone would make every
     * probe for a refused duplicate pass — while quietly forbidding the one
     * thing this table exists to express, namely that a person can work in more
     * than one brand. The only way to tell the two apart is to write the row
     * the narrower constraint has no business refusing.
     */
    protected function sameUserInAnotherBrandIsAccepted(int $otherBrandId, string $userId): bool
    {
        return $this->insertIsAccepted('brand_user', $this->membershipRow($otherBrandId, $userId));
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipRow(int $brandId, string $userId): array
    {
        return [
            'brand_id' => $brandId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Try the insert, report whether the database took it, and leave no trace
     * either way.
     */
    private function insertIsAccepted(string $table, array $row): bool
    {
        try {
            $id = $this->isolated()->table($table)->insertGetId($row);
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }

        $this->isolated()->table($table)->where('id', $id)->delete();

        return true;
    }
}
