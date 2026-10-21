<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\PersonSources;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/SimulatorTest.php';

/*
 * The dry run can copy a real person: the CRM finds her and knows her tags, the access addon knows what she holds, the
 * automations addon what she has been through. Read only; a source that throws or that the user may not use costs only
 * its own part.
 */

/** A CRM: finds Lena, knows her tags, and that she asked not to be written to. */
class SimCrmSource implements ProvidesSimPersons
{
    public function personSourceId(): string
    {
        return 'crm';
    }

    public function personsAuthorized($user): bool
    {
        return $user !== null && $user->can('see crm');
    }

    public function searchPersons(string $query, int $limit): array
    {
        return str_contains('lena', strtolower($query)) ? [['key' => '7', 'label' => 'Lena Probe']] : [];
    }

    public function identifyPerson(string $key): ?array
    {
        return $key === '7' ? ['email' => 'lena@example.test', 'id' => 7] : null;
    }

    public function personFacet(array $identity): array
    {
        return ['traits' => ['contact.tags' => ['chorleitung'], 'contact.status' => 'customer'], 'unsubscribed' => true];
    }
}

class SimAccessSource implements ProvidesSimPersons
{
    public function personSourceId(): string
    {
        return 'access';
    }

    public function personsAuthorized($user): bool
    {
        return $user !== null && $user->can('see access');
    }

    public function searchPersons(string $query, int $limit): array
    {
        return [];
    }

    public function identifyPerson(string $key): ?array
    {
        return null;
    }

    public function personFacet(array $identity): array
    {
        // Looked up by the identity the CRM gave.
        return $identity['email'] === 'lena@example.test' ? ['access' => ['kurs-stimme', 'kurs-stimme', 'extra']] : [];
    }
}

class SimBrokenSource extends SimAccessSource
{
    public function personSourceId(): string
    {
        return 'broken';
    }

    public function personsAuthorized($user): bool
    {
        return true;
    }

    public function personFacet(array $identity): array
    {
        throw new RuntimeException('lena@example.test exploded');
    }
}

function simUser(array $can): object
{
    return new class($can)
    {
        public function __construct(private array $can) {}

        public function can(string $ability): bool
        {
            return in_array($ability, $this->can, true);
        }
    };
}

function simSources(): PersonSources
{
    $sources = new PersonSources(app());

    foreach ([SimCrmSource::class, SimAccessSource::class] as $s) {
        $sources->register($s);
    }

    return $sources;
}

it('finds a person in the CRM and puts together what every source knows about her', function () {
    $sources = simSources();
    $user = simUser(['see crm', 'see access']);

    expect($sources->search('Lena', $user))->toBe([['key' => 'crm|7', 'label' => 'Lena Probe']]);

    $person = $sources->state('crm|7', $user);

    expect($person->isKnown())->toBeTrue()
        ->and($person->trait('contact.tags'))->toBe(['chorleitung'])
        ->and($person->trait('contact.status'))->toBe('customer')
        ->and($person->access)->toBe(['kurs-stimme', 'extra'])
        ->and($person->trait('marketing.unsubscribed'))->toBeTrue();
});

it('leaves out the part of a source the user may not read, and keeps the rest', function () {
    $sources = simSources();

    $person = $sources->state('crm|7', simUser(['see crm']));

    expect($person->trait('contact.tags'))->toBe(['chorleitung'])
        ->and($person->access)->toBe([]);

    // Not even the CRM: nobody is found, nobody is copied.
    expect($sources->search('Lena', simUser(['see access'])))->toBe([])
        ->and($sources->state('crm|7', simUser(['see access'])))->toBeNull()
        ->and($sources->state('crm|7', null))->toBeNull();
});

it('knows no key that is not one of its sources', function () {
    $sources = simSources();
    $user = simUser(['see crm', 'see access']);

    expect($sources->state('crm|999', $user))->toBeNull()
        ->and($sources->state('nope|7', $user))->toBeNull()
        ->and($sources->state('7', $user))->toBeNull()
        ->and($sources->state('crm|', $user))->toBeNull();
});

it('costs only its own part when a source fails, and does not log who was asked about', function () {
    $sources = simSources();
    $sources->register(SimBrokenSource::class);

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message;
    });

    $person = $sources->state('crm|7', simUser(['see crm', 'see access']));

    expect($person->trait('contact.tags'))->toBe(['chorleitung'])
        ->and($person->access)->toBe(['kurs-stimme', 'extra'])
        ->and($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('broken')
        ->and($logged[0])->toContain('RuntimeException')
        ->and($logged[0])->not->toContain('lena');
});

it('refuses a class that is no source and a name that is taken by another', function () {
    $sources = new PersonSources(app());

    expect(fn () => $sources->register(stdClass::class))->toThrow(InvalidArgumentException::class);

    $sources->register(SimCrmSource::class);

    expect(fn () => $sources->register(new class extends SimCrmSource {}::class))->toThrow(InvalidArgumentException::class);
});

it('reads and writes nothing of its own: no statement that is not a select', function () {
    $sources = simSources();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    $sources->search('Lena', simUser(['see crm']));
    $sources->state('crm|7', simUser(['see crm', 'see access']));

    expect($writes)->toBe([]);
});

it('silences the marketing mails from the start for a person who already asked not to be written to', function () {
    $sim = simSetUp();
    app(MapObjectRegistry::class)->register(SimMarketer::class);

    $person = simSources()->state('crm|7', simUser(['see crm']));
    $timeline = (new Simulator(app(MapObjectRegistry::class)))->timeline(
        [simNode('marketer', 'm')],
        [],
        ['type' => 'marketer', 'id' => 'm', 'event' => 'started'],
        $person,
    );

    expect(array_map(fn ($e) => $e['kind'].':'.$e['label'], $timeline['entries']))->toBe([
        'enter:MARKETER m',
        'stop:Unsubscribed: this marketing mail does not go out',
        'mail:A transactional mail',
    ]);
});

class SimMarketer extends SimFakeProvider
{
    public function type(): string
    {
        return 'marketer';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::STARTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [
            Effect::mail('A campaign', null, 0, ['marketing' => true]),
            Effect::mail('A transactional mail', null, 0, ['marketing' => false]),
        ];
    }
}
