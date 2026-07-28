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

    protected function setUp(): void
    {
        parent::setUp();

        // A dummy branded table + model to exercise the scope/trait without
        // pulling any real addon into the foundation package tests.
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

    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');

        parent::tearDown();
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
