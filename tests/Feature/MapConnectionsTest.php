<?php

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../Fixtures/IdentityStub.php';

class ConnPlainProvider implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'plain';
    }

    public function typeLabel(): string
    {
        return 'Plain';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

/** Duck-typed on purpose: does not implement ProvidesMapConnections. */
class ConnProvider extends ConnPlainProvider
{
    public static array $links = [];

    public static bool $allowed = true;

    public static bool $audit = true;

    /** @var array<string, mixed>|null what a disconnect removed and returns for the undo */
    public static ?array $restore = null;

    public function type(): string
    {
        return 'owner';
    }

    public function connectionRules(): array
    {
        return [['from_type' => 'owner', 'to_type' => 'target', 'kind' => 'triggers', 'label' => 'starts']];
    }

    public function connectionAuthorized(array $c, $user): bool
    {
        return self::$allowed;
    }

    public function connectionPreview(array $c, string $action): array
    {
        $linked = isset(self::$links[$c['from_id']]);

        if ($action === 'connect' && $linked) {
            throw new DomainException('Already connected.');
        }
        if ($action === 'disconnect' && ! $linked) {
            throw new DomainException('Not connected.');
        }

        return ['consequence' => "{$action} {$c['from_id']} to {$c['to_id']}", 'before' => $linked ? $c['to_id'] : '', 'after' => $linked ? '' : $c['to_id']];
    }

    public function connect(array $c): array
    {
        $this->connectionPreview($c, 'connect');
        self::$links[$c['from_id']] = $c['to_id'];

        return ['before' => '', 'after' => $c['to_id']];
    }

    public function disconnect(array $c): array
    {
        $this->connectionPreview($c, 'disconnect');
        unset(self::$links[$c['from_id']]);

        return ['before' => $c['to_id'], 'after' => ''] + (self::$restore !== null ? ['restore' => self::$restore] : []);
    }
}

class ConnDuplicateProvider extends ConnProvider
{
    public function type(): string
    {
        return 'second';
    }

    public function connectionRules(): array
    {
        return [
            ['from_type' => 'owner', 'to_type' => 'target', 'kind' => 'triggers', 'label' => 'second wins?'],
            ['from_type' => 'second', 'to_type' => 'target', 'kind' => 'unlocks', 'label' => 'unlocks'],
        ];
    }
}

class ConnThrowingRulesProvider extends ConnProvider
{
    public function type(): string
    {
        return 'rulesboom';
    }

    public function connectionRules(): array
    {
        throw new RuntimeException('down');
    }
}

class ConnBrokenWriteProvider extends ConnProvider
{
    public function type(): string
    {
        return 'writeboom';
    }

    public function connectionRules(): array
    {
        return [['from_type' => 'writeboom', 'to_type' => 'target', 'kind' => 'triggers', 'label' => 'breaks']];
    }

    public function connect(array $c): array
    {
        throw new RuntimeException('disk full');
    }
}

/**
 * Holds the same line the real statamic-activity does: `ActivityData::$actor`
 * is `?Identity`, so a Statamic user as actor is a TypeError there.
 */
class FakeActivityFacade
{
    public static array $recorded = [];

    public static bool $throws = false;

    /** What record() returns: null is "disabled or dropped by the sanitizer". */
    public static ?object $returns = null;

    public static function record(string $eventType, array $attributes = []): ?object
    {
        if (self::$throws) {
            throw new RuntimeException('activity down');
        }

        $actor = $attributes['actor'] ?? null;
        $identity = 'Goldnead\\IdentityContracts\\Identity';

        if ($actor !== null && class_exists($identity) && ! $actor instanceof $identity) {
            throw new TypeError('actor must be an Identity, got '.get_debug_type($actor));
        }

        self::$recorded[] = [$eventType, $attributes];

        return self::$returns;
    }
}

class RegistryWithActivity extends MapObjectRegistry
{
    protected function activityFacade(): ?string
    {
        return FakeActivityFacade::class;
    }
}

class RegistryWithoutActivity extends MapObjectRegistry
{
    protected function activityFacade(): ?string
    {
        return null;
    }
}

function connUser(): object
{
    return new class
    {
        public function id(): string
        {
            return 'u1';
        }

        public function email(): string
        {
            return 'ada@example.test';
        }

        public function name(): string
        {
            return 'Ada';
        }
    };
}

function connRegistry(string $class = RegistryWithoutActivity::class, array $providers = [ConnProvider::class]): MapObjectRegistry
{
    $registry = new $class(app());
    foreach ($providers as $provider) {
        $registry->register($provider);
    }

    return $registry;
}

beforeEach(function () {
    ConnProvider::$links = [];
    ConnProvider::$allowed = true;
    FakeActivityFacade::$recorded = [];
    FakeActivityFacade::$throws = false;
    FakeActivityFacade::$returns = new stdClass;
    ConnProvider::$restore = null;
});

const CONN = ['from_type' => 'owner', 'from_id' => 'a', 'to_type' => 'target', 'to_id' => 'b'];

it('collects rules from providers that have the methods and ignores the others', function () {
    $registry = connRegistry(providers: [ConnPlainProvider::class, ConnProvider::class]);

    expect($registry->connectionRules())->toBe([
        ['from_type' => 'owner', 'to_type' => 'target', 'kind' => 'triggers', 'label' => 'starts', 'owner' => 'owner'],
    ]);
});

it('lets the first provider win on a duplicate pair and kind', function () {
    $registry = connRegistry(providers: [ConnProvider::class, ConnDuplicateProvider::class]);

    $rules = $registry->connectionRules();

    expect($rules)->toHaveCount(2)
        ->and($rules[0]['label'])->toBe('starts')
        ->and($rules[1]['from_type'])->toBe('second');
});

it('lets a provider that throws in connectionRules cost only its own rules', function () {
    Log::spy();
    $registry = connRegistry(providers: [ConnProvider::class, ConnThrowingRulesProvider::class]);

    expect($registry->connectionRules())->toHaveCount(1);
    Log::shouldHaveReceived('warning')->once();
});

it('finds the one rule for a pair', function () {
    $registry = connRegistry();

    expect($registry->ruleFor('owner', 'target')['kind'])->toBe('triggers')
        ->and($registry->ruleFor('target', 'owner'))->toBeNull();
});

it('previews with the kind filled in from the rule', function () {
    $preview = connRegistry()->previewConnection(CONN, 'connect', connUser());

    expect($preview)->toBe(['kind' => 'triggers', 'consequence' => 'connect a to b', 'before' => '', 'after' => 'b']);
});

it('rejects an unknown pair or action with InvalidArgumentException', function () {
    $registry = connRegistry();

    expect(fn () => $registry->previewConnection(['to_type' => 'owner', 'from_type' => 'target'] + CONN, 'connect', connUser()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->previewConnection(CONN, 'explode', connUser()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->applyConnection(['to_type' => 'nope'] + CONN, 'connect', connUser()))
        ->toThrow(InvalidArgumentException::class);
});

it('passes the DomainException of the provider through', function () {
    expect(fn () => connRegistry()->previewConnection(CONN, 'disconnect', connUser()))
        ->toThrow(DomainException::class, 'Not connected.');
});

it('refuses with AuthorizationException and writes nothing', function () {
    ConnProvider::$allowed = false;

    expect(fn () => connRegistry(RegistryWithActivity::class)->applyConnection(CONN, 'connect', connUser()))
        ->toThrow(AuthorizationException::class);

    expect(ConnProvider::$links)->toBe([])
        ->and(FakeActivityFacade::$recorded)->toBe([]);
});

it('writes, audits through the activity facade and offers the undo', function () {
    $result = connRegistry(RegistryWithActivity::class)->applyConnection(CONN, 'connect', connUser());

    expect(ConnProvider::$links)->toBe(['a' => 'b'])
        ->and($result['kind'])->toBe('triggers')
        ->and($result['after'])->toBe('b')
        ->and($result['undo'])->toEqual(['action' => 'disconnect', 'kind' => 'triggers'] + CONN);

    [$event, $attributes] = FakeActivityFacade::$recorded[0];

    expect($event)->toBe('map.connection_made')
        ->and($attributes['actor'])->toBeInstanceOf(Goldnead\IdentityContracts\Identity::class)
        ->and($attributes['actor']->id)->toBe('u1')
        ->and($attributes['properties']['user_id'])->toBe('u1')
        ->and(json_encode($attributes))->not->toContain('ada@example.test')->not->toContain('Ada')
        ->and($attributes['properties']['from_type'])->toBe('owner')
        ->and($attributes['properties']['to_id'])->toBe('b')
        ->and($attributes['properties']['kind'])->toBe('triggers')
        ->and($attributes['properties']['consequence'])->toBe('connect a to b')
        ->and($attributes['properties']['before'])->toBe('')
        ->and($attributes['properties']['after'])->toBe('b')
        ->and($attributes['properties']['at'])->toBeString();
});

it('stores the audit in a table when statamic-activity is not installed, with the user id only', function () {
    connRegistry()->applyConnection(CONN, 'connect', connUser());

    $row = DB::table('map_connection_audits')->first();

    expect($row->event)->toBe('map.connection_made')
        ->and($row->user_id)->toBe('u1')
        ->and([$row->from_type, $row->from_id, $row->to_type, $row->to_id, $row->kind])->toBe(['owner', 'a', 'target', 'b', 'triggers'])
        ->and($row->after)->toBe('b')
        ->and(json_encode($row))->not->toContain('ada@example.test')->not->toContain('Ada');
});

it('stores the audit in the table too when activity keeps nothing (disabled or dropped)', function () {
    FakeActivityFacade::$returns = null;

    connRegistry(RegistryWithActivity::class)->applyConnection(CONN, 'connect', connUser());

    expect(DB::table('map_connection_audits')->count())->toBe(1);
});

it('does not store a second audit row when activity kept the record', function () {
    connRegistry(RegistryWithActivity::class)->applyConnection(CONN, 'connect', connUser());

    expect(DB::table('map_connection_audits')->count())->toBe(0);
});

it('refuses a preview for a user who may not write it', function () {
    ConnProvider::$allowed = false;

    expect(fn () => connRegistry()->previewConnection(CONN, 'connect', connUser()))
        ->toThrow(AuthorizationException::class);
});

it('writes nothing and says so when the field changed since the user confirmed', function () {
    expect(fn () => connRegistry()->applyConnection(CONN, 'connect', connUser(), 'something-else'))
        ->toThrow(Goldnead\BrandContext\Map\ConnectionConflict::class);

    expect(ConnProvider::$links)->toBe([])
        ->and(DB::table('map_connection_audits')->count())->toBe(0);

    // The value the user was shown goes through.
    connRegistry()->applyConnection(CONN, 'connect', connUser(), '');
    expect(ConnProvider::$links)->toBe(['a' => 'b']);
});

it('hands what a disconnect removed to the undo and strips it from the undo of the undo', function () {
    $registry = connRegistry();
    $registry->applyConnection(CONN, 'connect', connUser());
    ConnProvider::$restore = ['id' => 'row-1', 'place' => 'start'];

    $removed = $registry->applyConnection(CONN, 'disconnect', connUser());

    expect($removed['undo'])->toEqual(['action' => 'connect', 'kind' => 'triggers', 'restore' => ['id' => 'row-1', 'place' => 'start']] + CONN);

    $undo = $removed['undo'];
    unset($undo['action']);
    $again = $registry->applyConnection($undo, 'connect', connUser());

    expect($again['undo'])->not->toHaveKey('restore');
});

it('detects the real activity facade by class_exists', function () {
    $registry = new class(app()) extends MapObjectRegistry
    {
        public function facade(): ?string
        {
            return $this->activityFacade();
        }
    };

    expect($registry->facade())->toBe(class_exists('Goldnead\Activity\Facades\Activity') ? 'Goldnead\Activity\Facades\Activity' : null);
});

it('undoes the connection exactly and audits the removal', function () {
    $registry = connRegistry(RegistryWithActivity::class);
    $made = $registry->applyConnection(CONN, 'connect', connUser());

    $undo = $made['undo'];
    $action = $undo['action'];
    unset($undo['action']);
    $removed = $registry->applyConnection($undo, $action, connUser());

    expect(ConnProvider::$links)->toBe([])
        ->and($removed['undo']['action'])->toBe('connect')
        ->and(FakeActivityFacade::$recorded[1][0])->toBe('map.connection_removed');
});

it('does not roll back or fail when the audit fails', function () {
    FakeActivityFacade::$throws = true;
    Log::spy();

    $result = connRegistry(RegistryWithActivity::class)->applyConnection(CONN, 'connect', connUser());

    expect(ConnProvider::$links)->toBe(['a' => 'b'])
        ->and($result['after'])->toBe('b');
    Log::shouldHaveReceived('warning')->once();
});

it('logs and rethrows a provider failure while writing', function () {
    Log::spy();
    $registry = connRegistry(providers: [ConnBrokenWriteProvider::class]);

    expect(fn () => $registry->applyConnection(['from_type' => 'writeboom'] + CONN, 'connect', connUser()))
        ->toThrow(RuntimeException::class, 'disk full');
    Log::shouldHaveReceived('error')->once();
});
