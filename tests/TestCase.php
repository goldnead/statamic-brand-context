<?php

namespace Goldnead\BrandContext\Tests;

use Goldnead\BrandContext\ServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * A dummy branded table + model to exercise the scope and the trait without
     * pulling a real addon into the foundation package's own suite.
     *
     * **Created with the migrations, once per process, and never dropped
     * between tests.** It used to be a `Schema::create()` in `setUp()` and a
     * `Schema::dropIfExists()` in `tearDown()`, and under MySQL that quietly
     * disabled the isolation the whole suite relies on: DDL commits
     * implicitly, so each of those two statements ended the transaction
     * `RefreshDatabase` had just opened, and the rollback afterwards had
     * nothing left to roll back. Measured on 08.09.2026 — a single test that
     * stores one settings row left that row in the database after the run, and
     * every following test met it. The suite is green on SQLite either way,
     * because SQLite rolls DDL back like anything else, which is precisely why
     * this could sit here unseen.
     *
     * {@see MigrationPathTestCase} names the same rule for the same reason and
     * gives its migrations a connection of their own. Here the rule is kept by
     * not issuing DDL inside a test at all: this hook runs on
     * `DatabaseRefreshed`, so before the first transaction is opened, and the
     * table's rows are rolled back per test like every other table's.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('email')->nullable();
            $table->string('handle')->nullable();
            // Globally unique, like a confirmation or unsubscribe token: this is
            // the precondition that makes deriving a brand from it safe.
            $table->string('token')->nullable()->unique();
            $table->timestamps();
            $table->unique(['brand_id', 'email']);
        });
    }

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The public-route tests run through the real `web` group, which
        // encrypts cookies and therefore needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        // Default to single-brand; individual tests flip this on.
        $app['config']->set('brand-context.multi_brand', false);
    }

    /**
     * In-memory SQLite by default, so the suite keeps running anywhere with no
     * setup. Set `DB_DRIVER=mysql` to point the identical suite at a real MySQL
     * server instead — see phpunit.mysql.xml.
     *
     * SQLite is not a substitute for that run. It has no InnoDB key-length
     * limit, no utf8mb4 byte arithmetic, no fixed column widths and no real
     * foreign keys unless they are asked for, which is precisely why a fully
     * green suite in statamic-notifications let an unbuildable index reach
     * production. `tests/Unit/IndexKeyLengthTest.php` closes that gap without a
     * server; this closes it with one.
     *
     * It matters more here than in any sibling. This package owns `brands` and
     * `brand_user` — the tables every sibling's `brand_id` migration reads and
     * constrains against — and until now there was no way at all to run its
     * suite against the engine those siblings run on.
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'brand_context_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function enableMultiBrand($licenseCheck = null): void
    {
        config()->set('brand-context.multi_brand', true);
        config()->set('brand-context.license_check', $licenseCheck);
        app('brand-context')->forget();
    }
}
