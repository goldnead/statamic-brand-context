<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** Base for the dry-run providers: one object per id, label = id. */
abstract class SimFakeProvider implements ProvidesMapObjects
{
    abstract public function type(): string;

    public function typeLabel(): string
    {
        return ucfirst($this->type());
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return new MapObject($this->type(), $id, strtoupper($this->type()).' '.$id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function simulationAuthorized(string $id, $user): bool
    {
        return true;
    }
}

class SimLeadMagnet extends SimFakeProvider
{
    public function type(): string
    {
        return 'lead_magnet';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::REQUESTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return Effect::chain([Effect::mail('Please confirm', 'Double opt-in')], [
            Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['triggers'])),
        ]);
    }
}

class SimAutomation extends SimFakeProvider
{
    public function type(): string
    {
        return 'automation';
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        if ($id === 'fork') {
            return [Effect::fork('Tag?', [
                Effect::alt('A: yes', [Effect::mail('Same')]),
                Effect::alt('B: yes', [Effect::mail('Same')]),
                Effect::alt('C: no', [Effect::stop('Stops')]),
            ])];
        }

        return Effect::chain([
            Effect::mail('Welcome'),
            Effect::wait('Wait 2 days', 2 * 86400),
            Effect::mail('Follow-up'),
        ]);
    }
}

class SimProduct extends SimFakeProvider
{
    public function type(): string
    {
        return 'product';
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::access('Access granted'), Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['triggers']))];
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::PURCHASED)];
    }
}

/** A newsletter card: a provider that cannot say what it does. */
class SimNewsletter extends SimFakeProvider
{
    public function type(): string
    {
        return 'newsletter';
    }
}

class SimGuarded extends SimAutomation
{
    public function type(): string
    {
        return 'guarded';
    }

    public function simulationAuthorized(string $id, $user): bool
    {
        return false;
    }
}

function simSetUp(): Simulator
{
    $r = app(MapObjectRegistry::class);

    foreach ([SimLeadMagnet::class, SimAutomation::class, SimProduct::class, SimNewsletter::class, SimGuarded::class] as $p) {
        $r->register($p);
    }

    return new Simulator($r);
}

function simNode(string $type, string $id): array
{
    return ['type' => $type, 'id' => $id];
}

function simEdge(string $from, string $to, string $kind = 'triggers'): array
{
    [$ft, $fi] = explode(':', $from);
    [$tt, $ti] = explode(':', $to);

    return ['from_type' => $ft, 'from_id' => $fi, 'to_type' => $tt, 'to_id' => $ti, 'kind' => $kind];
}

/** Flat list of "kind:label" in tree order. */
function simFlat(array $nodes): array
{
    $out = [];

    foreach ($nodes as $node) {
        $out[] = $node['kind'].':'.$node['label'];
        array_push($out, ...simFlat($node['children']));
    }

    return $out;
}

it('walks the way from a lead magnet through its automation, in order, with a fork after every mail', function () {
    $sim = simSetUp();

    $tree = $sim->tree(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'welcome'), simNode('product', 'p')],
        [simEdge('lead_magnet:lm', 'automation:welcome')],
    );

    expect($tree['roots'])->toHaveCount(2); // the lead magnet and the product: nothing points at them

    $lm = simFlat([$tree['roots'][0]]);

    expect($lm)->toBe([
        'enter:LEAD_MAGNET lm',
        'mail:Please confirm',
        'fork:After this mail',
        'alt:opened · clicked · nothing: the sequence goes on',
        'enter:AUTOMATION welcome',
        'mail:Welcome',
        'fork:After this mail',
        'alt:opened · clicked · nothing: the sequence goes on',
        'wait:Wait 2 days',
        'mail:Follow-up',
        'fork:After this mail',
        'alt:opened · clicked · nothing: the sequence goes on',
        'alt:bought: PRODUCT p',
        'enter:PRODUCT p',
        'access:Access granted',
        'rejoin:the sequence goes on as above',
        'alt:bought: PRODUCT p',
        'enter:PRODUCT p',
        'access:Access granted',
        'rejoin:the sequence goes on as above',
        'alt:bought: PRODUCT p',
        'enter:PRODUCT p',
        'access:Access granted',
        'rejoin:the sequence goes on as above',
    ]);
});

it('counts the leaves and numbers the nodes in tree order', function () {
    $sim = simSetUp();

    $tree = $sim->tree([simNode('automation', 'a')], [], SimPerson::unknown());

    // The automation has no entry event: nothing starts, nothing is shown.
    expect($tree['roots'])->toBe([])
        ->and($tree['stats'])->toBe(['nodes' => 0, 'leaves' => 0, 'cut' => 0, 'unreached' => ['automation:a']]);

    $tree = $sim->tree([simNode('lead_magnet', 'lm'), simNode('automation', 'a')], [simEdge('lead_magnet:lm', 'automation:a')]);
    $ids = [];
    $walk = function (array $list) use (&$walk, &$ids) {
        foreach ($list as $node) {
            $ids[] = $node['id'];
            $walk($node['children']);
        }
    };
    $walk($tree['roots']);

    expect($ids)->toBe(array_map(fn ($i) => 'n'.$i, range(1, count($ids))))
        ->and($tree['stats']['nodes'])->toBe(count($ids))
        ->and($tree['stats']['leaves'])->toBeGreaterThan(0);
});

it('merges alternatives that lead to the same place and keeps the different one', function () {
    $sim = simSetUp();
    $r = app(MapObjectRegistry::class);

    // Start the "fork" automation by an own entry.
    $r->register(SimForkStarter::class);

    $tree = $sim->tree([simNode('forkstart', 's'), simNode('automation', 'fork')], [simEdge('forkstart:s', 'automation:fork', 'starts')]);

    $fork = $tree['roots'][0]['children'][0]['children'][0];

    expect($fork['kind'])->toBe('fork')
        ->and($fork['children'])->toHaveCount(2)
        ->and($fork['children'][0]['label'])->toBe('A: yes · B: yes')
        ->and($fork['children'][0]['count'])->toBe(2)
        ->and($fork['children'][1]['label'])->toBe('C: no');
});

class SimForkStarter extends SimFakeProvider
{
    public function type(): string
    {
        return 'forkstart';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::STARTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::emit(new SimEvent(SimEvent::ENTERED, via: ['starts']))];
    }
}

it('shows the structure on through an object that cannot say what it does, and says so', function () {
    $sim = simSetUp();

    $tree = $sim->tree(
        [simNode('lead_magnet', 'lm'), simNode('newsletter', 'nl'), simNode('automation', 'a')],
        [simEdge('lead_magnet:lm', 'newsletter:nl', 'sends'), simEdge('newsletter:nl', 'automation:a', 'starts')],
    );

    // The lead magnet only emits along "triggers"; the newsletter is reached by no event, so it is no root either.
    expect(simFlat($tree['roots']))->not->toContain('enter:NEWSLETTER nl');

    $tree = $sim->tree(
        [simNode('newsletter', 'nl'), simNode('product', 'p')],
        [simEdge('newsletter:nl', 'product:p', 'sells')],
    );

    // A newsletter has no entry but a connection leading on: it starts the structure, its effect is unknown.
    expect(simFlat($tree['roots']))->toContain('enter:NEWSLETTER nl')
        ->toContain('unknown:Effect unknown')
        ->toContain('enter:PRODUCT p');
});

it('refuses a dry run the user may not see and shows no effects of it', function () {
    $sim = simSetUp();
    $r = app(MapObjectRegistry::class);
    $r->register(SimGuardedStarter::class);

    $tree = $sim->tree(
        [simNode('guardedstart', 's'), simNode('guarded', 'g')],
        [simEdge('guardedstart:s', 'guarded:g', 'starts')],
    );

    $flat = simFlat($tree['roots']);

    expect($flat)->toContain('denied:You may not see this dry run')
        ->and($flat)->not->toContain('mail:Welcome');
});

class SimGuardedStarter extends SimForkStarter
{
    public function type(): string
    {
        return 'guardedstart';
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::emit(new SimEvent(SimEvent::ENTERED, via: ['starts']))];
    }
}

it('stops a loop on the path instead of walking it for ever', function () {
    $sim = simSetUp();

    $tree = $sim->tree(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'a'), simNode('automation', 'b')],
        [simEdge('lead_magnet:lm', 'automation:a'), simEdge('automation:a', 'automation:b', 'starts'), simEdge('automation:b', 'automation:a', 'starts')],
    );

    expect(true)->toBeTrue(); // reached: no endless recursion
    expect($tree['stats']['nodes'])->toBeLessThan(Simulator::MAX_NODES);
});

it('caps the tree and says how many ways it cut', function () {
    $sim = simSetUp();
    $nodes = [simNode('lead_magnet', 'lm')];
    $edges = [];

    foreach (range(1, 12) as $i) {
        $nodes[] = simNode('automation', "a$i");
        $nodes[] = simNode('product', "p$i");
        $edges[] = simEdge('lead_magnet:lm', "automation:a$i");
    }

    $tree = $sim->tree($nodes, $edges);

    expect($tree['stats']['nodes'])->toBeLessThanOrEqual(Simulator::MAX_NODES + 60)
        ->and($tree['stats']['cut'])->toBeGreaterThan(0);

    $more = collect(simFlat($tree['roots']))->filter(fn ($l) => str_starts_with($l, 'more:'));

    expect($more)->not->toBeEmpty();
});

it('writes nothing, sends nothing, dispatches nothing', function () {
    $sim = simSetUp();
    Mail::fake();
    Queue::fake();
    Event::fake();

    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    $sim->tree(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'a'), simNode('product', 'p')],
        [simEdge('lead_magnet:lm', 'automation:a')],
        SimPerson::unknown(),
    );
    $sim->starts([simNode('lead_magnet', 'lm')], []);
    $sim->timeline([simNode('lead_magnet', 'lm'), simNode('automation', 'a')], [simEdge('lead_magnet:lm', 'automation:a')], ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'], SimPerson::known());

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();

    expect($writes)->toBe([]);
});

/** An object that calls another one by name: `a` calls `b`, `b` calls `a`, `x` calls an object the map does not show. */
class SimCaller extends SimFakeProvider
{
    public function type(): string
    {
        return 'caller';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::STARTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $callee = ['a' => 'b', 'b' => 'a', 'x' => 'off'][$id] ?? null;

        return $callee === null ? [Effect::mail("Mail of $id")] : [Effect::emit(new SimEvent(SimEvent::ENTERED, target: ['type' => 'caller', 'id' => $callee]))];
    }
}

it('starts a loop of calls where its own provider says it begins, and stops it at the second visit', function () {
    $sim = simSetUp();
    app(MapObjectRegistry::class)->register(SimCaller::class);

    $tree = $sim->tree(
        [simNode('caller', 'a'), simNode('caller', 'b')],
        [simEdge('caller:a', 'caller:b', 'starts'), simEdge('caller:b', 'caller:a', 'starts')],
    );

    expect(simFlat($tree['roots']))->toBe(['enter:CALLER a', 'enter:CALLER b', 'rejoin:CALLER a is already running on this path'])
        ->and($tree['stats']['unreached'])->toBe([]);
});

it('follows a call to an object the map does not show, and says so', function () {
    $sim = simSetUp();
    app(MapObjectRegistry::class)->register(SimCaller::class);

    $tree = $sim->tree([simNode('caller', 'x')], []);

    expect(simFlat($tree['roots']))->toBe(['enter:CALLER x', 'enter:CALLER off', 'mail:Mail of off', 'fork:After this mail', 'alt:opened · clicked · nothing: the sequence goes on'])
        ->and($tree['roots'][0]['children'][0]['meta']['off_map'])->toBeTrue();
});

it('does not walk along a hand-drawn note', function () {
    $sim = simSetUp();

    $tree = $sim->tree(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'a')],
        [simEdge('lead_magnet:lm', 'automation:a', 'hand')],
    );

    expect(simFlat($tree['roots']))->not->toContain('enter:AUTOMATION a')
        ->and($tree['stats']['unreached'])->toBe(['automation:a']);
});

it('says how many starts it could not make when the budget is spent', function () {
    $sim = simSetUp();
    $nodes = [];

    foreach (range(1, 300) as $i) {
        $nodes[] = simNode('product', "p$i");
    }

    $tree = $sim->tree($nodes, []);
    $last = end($tree['roots']);

    expect($last['kind'])->toBe('more')
        ->and($tree['stats']['cut'])->toBeGreaterThan(0)
        ->and($last['meta']['ways'])->toBe(300 - (count($tree['roots']) - 1));
});

it('names the places a way can begin, with the event each one answers to', function () {
    $sim = simSetUp();

    $starts = $sim->starts(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'welcome'), simNode('product', 'p')],
        [simEdge('lead_magnet:lm', 'automation:welcome')],
    );

    expect($starts)->toBe([
        ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested', 'label' => 'LEAD_MAGNET lm', 'type_label' => 'Lead_magnet'],
        ['type' => 'product', 'id' => 'p', 'event' => 'purchased', 'label' => 'PRODUCT p', 'type_label' => 'Product'],
    ]);
});

it('walks one known person along one line: no fork, the clock in days', function () {
    $sim = simSetUp();

    $timeline = $sim->timeline(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'welcome'), simNode('product', 'p')],
        [simEdge('lead_magnet:lm', 'automation:welcome')],
        ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'],
        SimPerson::known(),
    );

    expect(array_map(fn ($e) => $e['day'].' '.$e['kind'].':'.$e['label'], $timeline['entries']))->toBe([
        '0 enter:LEAD_MAGNET lm',
        '0 mail:Please confirm',
        '0 enter:AUTOMATION welcome',
        '0 mail:Welcome',
        '2 wait:Wait 2 days',
        '2 mail:Follow-up',
    ])
        ->and($timeline['entries'][4]['at'])->toBe(2 * 86400)
        ->and($timeline['stats'])->toBe(['entries' => 6, 'days' => [0, 2], 'cut' => 0]);
});

it('keeps a known person immutable and answers for her', function () {
    $p = SimPerson::known(['tag' => 'chor'], ['kurs']);
    $q = $p->withAccess('masterclass')->withTrait('tag', 'solo')->withRun('welcome');

    expect($p->isKnown())->toBeTrue()
        ->and($p->hasAccess('masterclass'))->toBeFalse()
        ->and($q->hasAccess('masterclass'))->toBeTrue()
        ->and($q->trait('tag'))->toBe('solo')
        ->and($p->trait('tag'))->toBe('chor')
        ->and($q->hasRun('welcome'))->toBeTrue()
        ->and(SimPerson::unknown()->isKnown())->toBeFalse();
});
