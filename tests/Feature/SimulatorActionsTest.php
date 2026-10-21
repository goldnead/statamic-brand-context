<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimAction;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;

require_once __DIR__.'/SimulatorTest.php';

/*
 * What the test person does after a mail (buys, clicks, unsubscribes), what she was given while walking,
 * and why each step is on her way.
 */

/** A provider whose product gives access with its slug, as the products addon says it. */
class SimGiver extends SimFakeProvider
{
    public function type(): string
    {
        return 'giver';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::PURCHASED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::access('Gives kurs', null, ['slug' => 'kurs']), Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['triggers']))];
    }
}

/** An automation that only does something for a person who holds the access, and says whether she has run it. */
class SimGated extends SimFakeProvider
{
    public function type(): string
    {
        return 'gated';
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return $person->hasAccess('kurs')
            ? [Effect::mail('Gated mail', $person->hasRun('gated') ? 'already run' : 'first run')]
            : [Effect::stop('No access yet')];
    }
}

/** A newsletter: two marketing mails two days apart. */
class SimLetters extends SimFakeProvider
{
    public function type(): string
    {
        return 'letters';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::STARTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return Effect::chain([
            Effect::mail('Letter one', null, 0, ['node' => 'l1', 'marketing' => true]),
            Effect::wait('after 2 days', 2 * 86400),
            Effect::mail('Letter two', null, 0, ['node' => 'l2', 'marketing' => true]),
        ]);
    }
}

function actSim(): Simulator
{
    $sim = simSetUp();
    $r = app(MapObjectRegistry::class);

    foreach ([SimGiver::class, SimGated::class, SimLetters::class] as $p) {
        $r->register($p);
    }

    return $sim;
}

function actLabels(array $timeline): array
{
    return array_map(fn ($e) => $e['day'].' '.$e['kind'].':'.$e['label'], $timeline['entries']);
}

it('lets the person buy after a mail: the purchase is handed to the product, a day later', function () {
    $sim = actSim();
    $person = SimPerson::known(actions: [new SimAction(SimAction::BUY, 'lead_magnet:lm|Please confirm', product: 'product:p', afterSeconds: 86400)]);

    $timeline = $sim->timeline(
        [simNode('lead_magnet', 'lm'), simNode('product', 'p')],
        [],
        ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'],
        $person,
    );

    expect(actLabels($timeline))->toBe([
        '0 enter:LEAD_MAGNET lm',
        '0 mail:Please confirm',
        '1 action:buys: PRODUCT p',
        '1 enter:PRODUCT p',
        '1 access:Access granted',
    ]);
});

it('says what she does when she clicks, and does not click anything', function () {
    $sim = actSim();
    $person = SimPerson::known(actions: [new SimAction(SimAction::CLICK, 'lead_magnet:lm|Please confirm', url: 'https://site.test/kurs')]);

    $timeline = $sim->timeline([simNode('lead_magnet', 'lm')], [], ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'], $person);

    expect(actLabels($timeline))->toContain('0 action:clicks: https://site.test/kurs');
});

it('stops the marketing mails after she unsubscribed, on the day she did, and keeps the ones before', function () {
    $sim = actSim();
    $person = SimPerson::known(actions: [new SimAction(SimAction::UNSUBSCRIBE, 'letters:nl|l1', afterSeconds: 86400)]);

    $timeline = $sim->timeline([simNode('letters', 'nl')], [], ['type' => 'letters', 'id' => 'nl', 'event' => 'started'], $person);

    expect(actLabels($timeline))->toBe([
        '0 enter:LETTERS nl',
        '0 mail:Letter one',
        '1 action:unsubscribes',
        '2 wait:after 2 days',
        '2 stop:Unsubscribed: this marketing mail does not go out',
    ])
        ->and($timeline['entries'][4]['detail'])->toBe('Letter two');
});

it('lets what she was given count for the steps after it', function () {
    $sim = actSim();
    $nodes = [simNode('giver', 'g'), simNode('gated', 'a')];
    $edges = [simEdge('giver:g', 'gated:a')];

    $with = $sim->timeline($nodes, $edges, ['type' => 'giver', 'id' => 'g', 'event' => 'purchased'], SimPerson::known());
    $without = $sim->timeline([simNode('gated', 'a')], [], ['type' => 'gated', 'id' => 'a', 'event' => 'started'], SimPerson::known());

    // After the purchase she holds the access, so the automation behind it sends its mail; without it, it stops.
    expect(actLabels($with))->toContain('0 mail:Gated mail')
        ->and(actLabels($without))->toContain('0 stop:No access yet');
});

it('knows that she has been through an automation once she is in it', function () {
    $sim = actSim();

    $first = $sim->timeline([simNode('gated', 'a')], [], ['type' => 'gated', 'id' => 'a', 'event' => 'started'], SimPerson::known([], ['kurs']));
    $again = $sim->timeline([simNode('gated', 'a')], [], ['type' => 'gated', 'id' => 'a', 'event' => 'started'], SimPerson::known([], ['kurs'], ['gated']));

    expect($first['entries'][1]['detail'])->toBe('first run')
        ->and($again['entries'][1]['detail'])->toBe('already run');
});

it('says why each step is on the way: what was reached, by what, and what was decided', function () {
    $sim = simSetUp();

    $timeline = $sim->timeline(
        [simNode('lead_magnet', 'lm'), simNode('automation', 'welcome')],
        [['from_type' => 'lead_magnet', 'from_id' => 'lm', 'to_type' => 'automation', 'to_id' => 'welcome', 'kind' => 'triggers', 'label' => 'starts']],
        ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'],
        SimPerson::known(),
    );

    $welcome = collect($timeline['entries'])->firstWhere('label', 'Welcome');

    expect($welcome['why'])->toBe([
        'Lead_magnet “LEAD_MAGNET lm”: asked for',
        'Automation “AUTOMATION welcome”: reached over the connection “starts”',
    ])
        ->and($timeline['entries'][0]['why'])->toBe([]);
});

it('names a mail by its object and its step, or by its subject when it has no step', function () {
    expect(Simulator::mailKey('automation:welcome', ['node' => 'm1'], 'Welcome'))->toBe('automation:welcome|m1')
        ->and(Simulator::mailKey('lead_magnet:lm', [], 'Please confirm'))->toBe('lead_magnet:lm|Please confirm')
        ->and(Simulator::mailKey(null, [], 'x'))->toBe('|x');
});

it('takes an action only in a shape it understands, and caps what a person carries', function () {
    expect(SimAction::fromArray(['type' => 'buy', 'mail' => 'a|b', 'product' => 'product:p', 'after_seconds' => 3600])?->toArray())->toBe([
        'type' => 'buy', 'mail' => 'a|b', 'product' => 'product:p', 'url' => null, 'after_seconds' => 3600,
    ])
        ->and(SimAction::fromArray(['type' => 'buy', 'mail' => 'a|b']))->toBeNull()
        ->and(SimAction::fromArray(['type' => 'launch', 'mail' => 'a|b']))->toBeNull()
        ->and(SimAction::fromArray(['type' => 'click', 'mail' => '']))->toBeNull()
        ->and(SimAction::fromArray(['type' => 'click', 'mail' => 'a|b', 'after_seconds' => 99999999999])->afterSeconds)->toBe(365 * 86400)
        ->and(SimAction::fromArray(['type' => 'click', 'mail' => 'a|b', 'after_seconds' => -5])->afterSeconds)->toBe(0);

    $many = array_fill(0, 40, new SimAction(SimAction::CLICK, 'a|b'));

    expect(SimPerson::known(actions: $many)->actions)->toHaveCount(SimAction::MAX);
});

it('hands a purchase to a product the map does not show as an unknown line, not as a purchase', function () {
    $sim = actSim();
    $person = SimPerson::known(actions: [new SimAction(SimAction::BUY, 'lead_magnet:lm|Please confirm', product: 'product:elsewhere')]);

    $timeline = $sim->timeline([simNode('lead_magnet', 'lm')], [], ['type' => 'lead_magnet', 'id' => 'lm', 'event' => 'requested'], $person);

    expect(actLabels($timeline))->toContain('0 unknown:buys a product the map does not show');
});
