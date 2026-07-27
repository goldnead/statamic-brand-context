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
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Default to single-brand; individual tests flip this on.
        $app['config']->set('brand-context.multi_brand', false);
    }

    protected function enableMultiBrand($licenseCheck = null): void
    {
        config()->set('brand-context.multi_brand', true);
        config()->set('brand-context.license_check', $licenseCheck);
        app('brand-context')->forget();
    }
}
