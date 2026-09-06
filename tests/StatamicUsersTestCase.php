<?php

namespace Goldnead\BrandContext\Tests;

use Goldnead\BrandContext\ServiceProvider;
use Goldnead\BrandContext\UserBrandField;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Facades\User;
use Statamic\Licensing\Outpost;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;
use Statamic\Version as StatamicVersion;

/**
 * A test case with a real Statamic and a real users repository behind it.
 *
 * The rest of this suite deliberately runs without the CMS — the package has
 * to boot in a Statamic-less context, and a fake user source keeps the
 * membership logic testable. That is exactly the wrong tool for the brand
 * field on the user form: what it has to get right is what a *Statamic* user
 * does with a key it was never given a column or a blueprint entry for, and a
 * fake cannot be wrong about that.
 *
 * This case is the file driver, where a user is `users/<id>.yaml` and its id is
 * a uuid. The eloquent subclass lives in `tests/Users`, next to the tests that
 * use it. Testing only one of the two would prove the easier half: on eloquent
 * an unknown key is an unknown *column*, and writing one is a SQL error rather
 * than a stray line in a file.
 */
class StatamicUsersTestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeUserFiles();
    }

    protected function tearDown(): void
    {
        $this->purgeUserFiles();

        parent::tearDown();
    }

    /** Which users repository this case runs on: `file` or `eloquent`. */
    protected function usersRepository(): string
    {
        return 'file';
    }

    /**
     * The submitted value must not survive the save.
     *
     * "Survive" means something different per driver, and that difference is
     * the whole reason both are here: on the file driver a leak is an extra
     * line in the yaml, on eloquent it is a write to a column the `users`
     * table does not have. The subclass overrides this with its own question.
     */
    protected function assertBrandFieldNotStored(object $user): void
    {
        $this->assertStringNotContainsString(
            UserBrandField::HANDLE,
            (string) file_get_contents($user->path()),
            'The brand field was written into the user yaml.'
        );

        $this->assertFalse(
            User::find($user->id())->has(UserBrandField::HANDLE),
            'The brand field came back as stored user data.'
        );
    }

    /**
     * RefreshDatabase rolls the database back between tests; nothing rolls back
     * a directory of yaml files. Without this every test would inherit the
     * users written by every test before it in the same process, and a
     * membership count would be asserted against somebody else's rows.
     */
    protected function purgeUserFiles(): void
    {
        $path = $this->userDirectory();

        if (is_dir($path)) {
            (new Filesystem)->deleteDirectory($path);
        }
    }

    protected function userDirectory(): string
    {
        return sys_get_temp_dir().'/brand-context-users-'.getmypid();
    }

    /**
     * This package's provider **before** Statamic's, on purpose.
     *
     * It is the harder of the two orders and the one a real install can
     * produce: providers come from package discovery, and nothing guarantees
     * the CMS boots first. Listed the other way round, everything this addon
     * touches already exists by the time it looks — including Statamic's
     * Stache stores, which is precisely the thing it must not depend on. The
     * ordering defect that made every `User::all()` in the process fail was
     * invisible in this suite until the two lines were swapped.
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class, StatamicServiceProvider::class];
    }

    /**
     * The root-namespace `Statamic` alias. A real install has it in
     * config/app.php; testbench loads no package aliases, and Statamic's own
     * code reaches for the alias in more than one place.
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Statamic' => Statamic::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Multi-brand from the start: the field only exists under it, and the
        // provider makes some of its decisions once at boot.
        $app['config']->set('brand-context.multi_brand', true);

        $app['config']->set('statamic.users.repository', $this->usersRepository());

        // Under multi-brand the provider wires its brand resolvers into
        // Statamic's middleware groups, and refuses loudly when a group has no
        // SubstituteBindings to sit in front of — a guard that exists to catch
        // a real ordering bug, not one to soften for a test. A bare testbench
        // declares neither group, so they are declared here; Statamic's own
        // provider pushes onto `statamic.web` rather than replacing it.
        $app['router']->middlewareGroup('statamic.web', [SubstituteBindings::class]);

        // Statamic's Solo edition refuses a second user ("Statamic Pro is
        // required for multiple users"), and these tests need more than one.
        $app['config']->set('statamic.editions.pro', true);

        // The flat-file users live in a per-process temp directory rather than
        // in testbench's skeleton, so a run cannot leave files behind in a
        // checkout and two parallel runs cannot read each other's users.
        $app['config']->set('statamic.stache.stores.users.directory', $this->userDirectory());

        // Statamic reads its own version out of composer.lock, which the
        // testbench skeleton does not have. Answered from this repository's
        // lock file so a Statamic upgrade cannot leave the tests asserting
        // against a version nothing here runs.
        $app->bind(StatamicVersion::class, fn () => new class extends StatamicVersion
        {
            public function get()
            {
                $lock = json_decode((string) file_get_contents(__DIR__.'/../composer.lock'), true);

                foreach ($lock['packages'] ?? [] as $package) {
                    if (($package['name'] ?? null) === 'statamic/cms') {
                        return ltrim((string) $package['version'], 'v');
                    }
                }

                return '6.0.0';
            }
        });

        // Silenced rather than given a working version: a real HTTP request to
        // statamic.com from a test run would be worse than none, and nothing
        // here asserts anything about licensing.
        $app->singleton(Outpost::class, fn () => new class extends Outpost
        {
            public function __construct() {}

            public function radio() {}

            public function response()
            {
                return [];
            }
        });
    }
}
