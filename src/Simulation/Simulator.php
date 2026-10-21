<?php

namespace Goldnead\BrandContext\Simulation;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesMapPaths;
use Goldnead\BrandContext\Contracts\ProvidesMapSimulation;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;

/**
 * The planner of the dry run ("Probelauf") on a map. It reads, it never does.
 *
 * It works through events with a clock that only exists in memory: a start object is entered with
 * one of its entry events ({@see ProvidesMapPaths}), the owning
 * addon says what it would do ({@see ProvidesMapSimulation}), an
 * effect that hands an event on is delivered along the map's connections, and so on. There is no
 * `execute()` anywhere and no Laravel event: the only things called are the providers' pure
 * `react()` and `paths()`, and the registry's lookups.
 *
 * Without a known person the planner returns the tree of every way. After each mail it adds the
 * fork the operator asked for (opened, clicked, bought, nothing). Alternatives that lead to the
 * same place are merged, the tree is capped in size and depth, and what is cut is said in a line
 * "n more ways", so a big map neither explodes nor lies.
 */
class Simulator
{
    /** Most nodes in one tree. */
    public const MAX_NODES = 400;

    /** Deepest nesting of one path. */
    public const MAX_DEPTH = 40;

    /** Most alternatives under one fork. */
    public const MAX_ALTERNATIVES = 12;

    /** Most products offered in the "bought" fork of one mail. */
    public const MAX_PURCHASES = 4;

    /** Edge kinds that are not a way a person can take. */
    public const NOT_A_WAY = ['waits_for', 'hand'];

    private int $budget = self::MAX_NODES;

    private int $cut = 0;

    /** @var array<string, array{label: string, type_label: string}> */
    private array $labels = [];

    /** @var array<string, list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}>> */
    private array $out = [];

    /** @var array<string, true> */
    private array $present = [];

    /** @var array<string, true> */
    private array $entered = [];

    /** @var list<array{type: string, id: string}> */
    private array $products = [];

    private SimPerson $person;

    private CarbonImmutable $start;

    private mixed $user = null;

    public function __construct(private readonly MapObjectRegistry $registry) {}

    /**
     * @param  list<array{type: string, id: string}>  $nodes  the objects on the map, in the map's order
     * @param  list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string, label?: string}>  $edges
     * @param  mixed  $user  the Control Panel user whose rights apply
     * @return array{roots: list<array<string, mixed>>, stats: array{nodes: int, leaves: int, cut: int, unreached: list<string>}}
     */
    public function tree(array $nodes, array $edges, ?SimPerson $person = null, mixed $user = null, ?CarbonImmutable $start = null): array
    {
        $incoming = $this->prepare($nodes, $edges, $person, $user, $start);

        $roots = [];
        $left = 0;

        foreach ($nodes as $n) {
            $key = $n['type'].':'.$n['id'];

            if (isset($incoming[$key])) {
                continue;
            }

            $events = $this->registry->paths($n['type'], (string) $n['id']);

            // An object with no entry of its own but a connection leading on (a newsletter card)
            // is a start the map knows without knowing its effect: the structure still shows.
            if ($events === [] && isset($this->out[$key])) {
                $events = [new SimEvent(SimEvent::STARTED)];
            }

            foreach ($events as $event) {
                if (! $this->room()) {
                    $left++;

                    continue;
                }

                $node = $this->enter($n['type'], (string) $n['id'], $event, $this->context());

                if ($node !== null) {
                    $roots[] = $node;
                }
            }
        }

        // Cards with a connection pointing at them that no path has reached (two automations that call
        // each other, an automation with its own trigger that is also fed by a card that said nothing)
        // still begin where their own provider says they begin.
        foreach ($nodes as $n) {
            $key = $n['type'].':'.$n['id'];

            if (isset($this->entered[$key]) || ! isset($incoming[$key])) {
                continue;
            }

            $event = $this->registry->paths($n['type'], (string) $n['id'])[0] ?? null;

            if ($event === null) {
                continue;
            }

            if (! $this->room()) {
                $left++;

                continue;
            }

            $node = $this->enter($n['type'], (string) $n['id'], $event, $this->context());

            if ($node !== null) {
                $roots[] = $node;
            }
        }

        if ($left > 0) {
            $this->cut += $left;
            $roots[] = $this->more($left, 0);
        }

        $counter = 0;
        $leaves = 0;
        $number = function (array &$list) use (&$number, &$counter, &$leaves): void {
            foreach ($list as &$node) {
                $node['id'] = 'n'.(++$counter);

                if ($node['children'] === []) {
                    $leaves++;
                }

                $number($node['children']);
            }
        };
        $number($roots);

        // Cards no path reaches: said, never hidden. A map object the planner could not place is a finding.
        $unreached = array_values(array_diff(array_keys($this->present), array_keys($this->entered)));

        return ['roots' => $roots, 'stats' => ['nodes' => $counter, 'leaves' => $leaves, 'cut' => $this->cut, 'unreached' => $unreached]];
    }

    /**
     * Reset the planner for one plan and read the map into it.
     *
     * @param  list<array{type: string, id: string}>  $nodes
     * @param  list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string, label?: string}>  $edges
     * @return array<string, true> the objects some connection points at
     */
    private function prepare(array $nodes, array $edges, ?SimPerson $person, mixed $user, ?CarbonImmutable $start): array
    {
        $this->budget = self::MAX_NODES;
        $this->cut = 0;
        $this->labels = [];
        $this->out = [];
        $this->present = [];
        $this->entered = [];
        $this->products = [];
        $this->person = $person ?? SimPerson::unknown();
        $this->user = $user;
        $this->start = $start ?? CarbonImmutable::now();

        $incoming = [];

        foreach ($nodes as $n) {
            $this->present[$n['type'].':'.$n['id']] = true;

            if ($n['type'] === 'product') {
                $this->products[] = ['type' => 'product', 'id' => (string) $n['id']];
            }
        }

        foreach ($edges as $e) {
            $from = $e['from_type'].':'.$e['from_id'];
            $to = $e['to_type'].':'.$e['to_id'];

            // "waits for" is a dashed line, not a hand-over of people: whoever waits starts on her own. A
            // hand-drawn line is a note of the operator, not a connection: nobody walks along a note.
            if (! isset($this->present[$from], $this->present[$to]) || $from === $to || in_array($e['kind'], self::NOT_A_WAY, true)) {
                continue;
            }

            $this->out[$from][] = $e;
            $incoming[$to] = true;
        }

        return $incoming;
    }

    /**
     * Where a way can begin on this map, for a screen that lets someone choose: every object nothing
     * points at, with each event its provider names (a lead magnet: asked for; a product: bought).
     *
     * @param  list<array{type: string, id: string}>  $nodes
     * @param  list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string, label?: string}>  $edges
     * @return list<array{type: string, id: string, event: string, label: string, type_label: string}>
     */
    public function starts(array $nodes, array $edges): array
    {
        $incoming = $this->prepare($nodes, $edges, null, null, null);
        $starts = [];

        foreach ($nodes as $n) {
            $key = $n['type'].':'.$n['id'];

            if (isset($incoming[$key])) {
                continue;
            }

            $events = $this->registry->paths($n['type'], (string) $n['id']);

            // As in the tree: an object with no entry of its own but a connection leading on is a start.
            if ($events === [] && isset($this->out[$key])) {
                $events = [new SimEvent(SimEvent::STARTED)];
            }

            foreach ($events as $event) {
                $starts[] = ['type' => $n['type'], 'id' => (string) $n['id'], 'event' => $event->type] + $this->labelOf($n['type'], (string) $n['id']);
            }
        }

        return $starts;
    }

    /**
     * What happens to one known person who starts at one object: every effect in the order it happens,
     * with the day it falls on. A known person has an answer for every condition, so the way is one
     * line: no fork after a mail, and the providers decide instead of branching. What a provider cannot
     * decide it says as "effect unknown", and what the person does with a mail (nothing, by default) is
     * not asked.
     *
     * @param  list<array{type: string, id: string}>  $nodes
     * @param  list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string, label?: string}>  $edges
     * @param  array{type: string, id: string, event: string}  $startAt
     * @return array{entries: list<array<string, mixed>>, stats: array{entries: int, days: list<int>, cut: int}}
     */
    public function timeline(array $nodes, array $edges, array $startAt, SimPerson $person, mixed $user = null, ?CarbonImmutable $start = null): array
    {
        $this->prepare($nodes, $edges, $person, $user, $start);

        $root = $this->enter($startAt['type'], $startAt['id'], new SimEvent($startAt['event']), $this->context());
        $entries = [];

        $flatten = function (array $list, array $why) use (&$flatten, &$entries): void {
            foreach ($list as $node) {
                $entries[] = [
                    'id' => 'e'.(count($entries) + 1),
                    'at' => $node['at'],
                    'day' => intdiv($node['at'], 86400),
                    'kind' => $node['kind'],
                    'label' => $node['label'],
                    'detail' => $node['detail'],
                    'object' => $node['object'],
                    'meta' => $node['meta'],
                    'owner' => $node['owner'],
                    'why' => array_slice($why, -6),
                ];

                $next = $why;

                if ($node['kind'] === 'enter') {
                    $next[] = $this->whyEnter($node);
                } elseif ($node['kind'] === 'decision') {
                    $next[] = $node['label'].': '.$node['detail'];
                } elseif ($node['kind'] === 'action') {
                    $next[] = $node['label'];
                }

                $flatten($node['children'], $next);
            }
        };
        $flatten($root === null ? [] : [$root], []);

        // She unsubscribed at some moment: the marketing mails after it do not go out, on whatever branch they stand.
        // A person who already asked not to be written to (copied from the CRM) did so before the way began.
        $unsubscribedAt = $person->trait('marketing.unsubscribed') === true ? -1 : null;

        foreach ($entries as $entry) {
            if ($entry['kind'] === 'action' && ($entry['meta']['action'] ?? null) === SimAction::UNSUBSCRIBE) {
                $unsubscribedAt = min($unsubscribedAt ?? PHP_INT_MAX, $entry['at']);
            }
        }

        if ($unsubscribedAt !== null) {
            foreach ($entries as $i => $entry) {
                if ($entry['kind'] === 'mail' && ($entry['meta']['marketing'] ?? false) === true && $entry['at'] > $unsubscribedAt) {
                    $entries[$i]['kind'] = 'stop';
                    $entries[$i]['detail'] = $entry['label'];
                    $entries[$i]['label'] = __('brand-context::messages.sim_unsubscribed_mail');
                }
            }
        }

        // The way is one line, but a parallel split or a call can interleave: order by the clock, keep the walk's order inside a moment.
        usort($entries, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        foreach ($entries as $i => $entry) {
            $entries[$i]['id'] = 'e'.($i + 1);
        }

        return [
            'entries' => $entries,
            'stats' => [
                'entries' => count($entries),
                'days' => array_values(array_unique(array_column($entries, 'day'))),
                'cut' => $this->cut,
            ],
        ];
    }

    /** @return array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson} */
    private function context(): array
    {
        return ['path' => [], 'bought' => [], 'at' => 0, 'depth' => 0, 'here' => null, 'person' => $this->person];
    }

    /**
     * An object receives an event.
     *
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return array<string, mixed>|null
     */
    private function enter(string $type, string $id, SimEvent $event, array $ctx, ?string $edgeKind = null): ?array
    {
        $key = $type.':'.$id;

        if (in_array($key, $ctx['path'], true)) {
            return $this->leaf('rejoin', __('brand-context::messages.sim_again', ['name' => $this->labelOf($type, $id)['label']]), $ctx['at'], ['type' => $type, 'id' => $id]);
        }

        if (! $this->room()) {
            return null;
        }

        $this->entered[$key] = true;
        $ctx['path'][] = $key;
        $ctx['here'] = $key;
        $ctx['depth']++;

        if ($type === 'product' && $event->type === SimEvent::PURCHASED) {
            $ctx['bought'][] = $key;
        }

        $label = $this->labelOf($type, $id);
        $node = $this->node('enter', $label['label'], $label['type_label'], $ctx['at'], 0, ['type' => $type, 'id' => $id], ['event' => $event->type, 'edge' => $edgeKind]);
        $node['owner'] = $key;

        if ($this->registry->has($type) && ! $this->registry->simulationAllowed($type, $id, $this->user)) {
            $node['children'][] = $this->leaf('denied', __('brand-context::messages.sim_denied'), $ctx['at']);

            return $node;
        }

        $effects = $this->registry->canReact($type)
            ? $this->registry->react($type, $id, $event, $ctx['person'], $this->start->addSeconds($ctx['at']))
            : null;

        // From here on she has been through this automation: what comes after can ask.
        if ($type === 'automation') {
            $ctx['person'] = $ctx['person']->withRun($id);
        }

        if ($effects === null) {
            // Nothing is known about this object. The map still says where it leads, so the
            // structure carries on; the effect itself is said to be unknown.
            $node['children'][] = $this->leaf('unknown', __('brand-context::messages.sim_unknown'), $ctx['at']);
            array_push($node['children'], ...$this->deliver(new SimEvent(SimEvent::ENTERED, ['type' => $type, 'id' => $id]), $ctx));

            return $node;
        }

        $node['children'] = $this->expandList($effects, $ctx);

        return $node;
    }

    /**
     * @param  list<Effect>  $effects
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function expandList(array $effects, array $ctx): array
    {
        $nodes = [];
        $total = count($effects);

        foreach ($effects as $i => $effect) {
            if (! $this->room() || $ctx['depth'] > self::MAX_DEPTH) {
                $this->cut += $total - $i;
                $nodes[] = $this->more($total - $i, $ctx['at']);

                break;
            }

            array_push($nodes, ...$this->expand($effect, $ctx));

            // What she was given stays given for what follows on this path.
            if ($effect->kind === Effect::ACCESS && is_string($effect->meta['slug'] ?? null) && $effect->meta['slug'] !== '') {
                $ctx['person'] = $ctx['person']->withAccess($effect->meta['slug']);
            }
        }

        return $nodes;
    }

    /**
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function expand(Effect $effect, array $ctx): array
    {
        $at = $ctx['at'] + $effect->afterSeconds;
        $here = $effect->object ? $effect->object['type'].':'.$effect->object['id'] : $ctx['here'];

        if ($effect->kind === Effect::EMIT) {
            $inner = [...$ctx, 'at' => $at, 'here' => $here];
            $sent = $effect->emits ? $this->deliver($effect->emits, $inner) : [];

            return [...$sent, ...$this->expandList($effect->children, $inner)];
        }

        if (! $this->room()) {
            $this->cut++;

            return [$this->more(1, $at)];
        }

        $inner = [...$ctx, 'at' => $at, 'here' => $here, 'depth' => $ctx['depth'] + 1];
        $object = $effect->object ? $this->labelOf($effect->object['type'], $effect->object['id']) + $effect->object : null;
        $node = $this->node($effect->kind, $effect->label, $effect->detail, $at, $effect->afterSeconds, $object, $effect->meta);
        // Whose effect this is: the object that answered (or the one the provider says it belongs to).
        $node['owner'] = $here;

        if ($effect->kind === Effect::FORK) {
            $node['children'] = $this->mergeAlternatives($this->alternatives($effect->children, $inner));

            return [$node];
        }

        $children = $this->expandList($effect->children, $inner);

        if ($effect->kind === Effect::MAIL && $ctx['person']->isKnown()) {
            // A known person does what she does: no fork "opened, clicked, bought, nothing". What the operator
            // let her do after this mail is part of her way.
            $mailKey = self::mailKey($here, $effect->meta, $effect->label);
            $node['meta']['mail_key'] = $mailKey;

            foreach ($ctx['person']->actionsFor($mailKey) as $action) {
                array_push($children, ...$this->action($action, $inner, $at, $here));
            }
        } elseif ($effect->kind === Effect::MAIL) {
            $children = $this->mailFork($children, $inner);
        }

        $node['children'] = $children;

        return [$node];
    }

    /**
     * What a mail is called inside the simulation, so an action can be tied to it: the object it belongs to and
     * its step (or, for a mail without one, its subject).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function mailKey(?string $owner, array $meta, string $label): string
    {
        return ($owner ?? '').'|'.(is_string($meta['node'] ?? null) && $meta['node'] !== '' ? $meta['node'] : $label);
    }

    /**
     * One thing the person does after a mail, as lines of her way.
     *
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function action(SimAction $action, array $ctx, int $mailAt, ?string $owner): array
    {
        if (! $this->room()) {
            $this->cut++;

            return [$this->more(1, $mailAt)];
        }

        $at = $mailAt + $action->afterSeconds;
        $meta = ['action' => $action->type, 'url' => $action->url];

        if ($action->type === SimAction::UNSUBSCRIBE) {
            return [$this->node('action', __('brand-context::messages.sim_unsubscribes'), null, $at, $action->afterSeconds, null, $meta)];
        }

        if ($action->type === SimAction::CLICK) {
            return [$this->node('action', __('brand-context::messages.sim_clicks', ['link' => (string) $action->url]), null, $at, $action->afterSeconds, null, $meta)];
        }

        [$type, $id] = array_pad(explode(':', (string) $action->product, 2), 2, '');

        if ($type !== 'product' || ! isset($this->present[$action->product])) {
            return [$this->node('unknown', __('brand-context::messages.sim_buys_unknown'), null, $at, $action->afterSeconds, null, $meta)];
        }

        $node = $this->node('action', __('brand-context::messages.sim_buys', ['name' => $this->labelOf($type, $id)['label']]), null, $at, $action->afterSeconds, null, $meta);
        $bought = $this->enter($type, $id, new SimEvent(SimEvent::PURCHASED, $owner ? $this->split($owner) : null), [...$ctx, 'at' => $at]);

        if ($bought !== null) {
            $node['children'][] = $bought;
        }

        return [$node];
    }

    /**
     * The fork after a mail: the person opens it, clicks, buys, or does nothing. Opening, clicking
     * and doing nothing change nothing in a dry run that has no link targets yet, so they merge
     * into the way the sequence goes on anyway. Buying is a way of its own, once for each product
     * on the map she has not already bought on this path: it hands a purchase to the product, and
     * the original sequence goes on as it was ("on as above").
     *
     * @param  list<array<string, mixed>>  $continuation
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function mailFork(array $continuation, array $ctx): array
    {
        if (! $this->room()) {
            $this->cut++;

            return [...$continuation, $this->more(1, $ctx['at'])];
        }

        $alternatives = [];
        $same = $this->node('alt', __('brand-context::messages.sim_mail_same'), null, $ctx['at'], 0, null, ['merged' => ['opened', 'clicked', 'nothing']]);
        $same['children'] = $continuation;
        $alternatives[] = $same;

        $offered = 0;

        foreach ($this->products as $product) {
            $key = 'product:'.$product['id'];

            if (in_array($key, $ctx['bought'], true) || in_array($key, $ctx['path'], true)) {
                continue;
            }

            if ($offered >= self::MAX_PURCHASES) {
                $this->cut++;
                $alternatives[] = $this->more(1, $ctx['at']);

                break;
            }

            if (! $this->room()) {
                $this->cut++;
                $alternatives[] = $this->more(1, $ctx['at']);

                break;
            }

            $offered++;
            $event = new SimEvent(SimEvent::PURCHASED, $ctx['here'] ? $this->split($ctx['here']) : null);
            $bought = $this->enter('product', $product['id'], $event, $ctx);
            $alt = $this->node('alt', __('brand-context::messages.sim_mail_bought', ['name' => $this->labelOf('product', $product['id'])['label']]), null, $ctx['at'], 0, null, ['merged' => ['bought']]);
            $alt['children'] = array_values(array_filter([
                $bought,
                $this->leaf('rejoin', __('brand-context::messages.sim_rejoin'), $ctx['at']),
            ]));
            $alternatives[] = $alt;
        }

        $fork = $this->node('fork', __('brand-context::messages.sim_mail_fork'), null, $ctx['at'], 0, null, ['after_mail' => true]);
        $fork['children'] = $alternatives;

        return [$fork];
    }

    /**
     * @param  list<Effect>  $alts
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function alternatives(array $alts, array $ctx): array
    {
        $nodes = [];
        $total = count($alts);

        foreach ($alts as $i => $alt) {
            if ($i >= self::MAX_ALTERNATIVES || ! $this->room()) {
                $this->cut += $total - $i;
                $nodes[] = $this->more($total - $i, $ctx['at']);

                break;
            }

            $node = $this->node('alt', $alt->label, $alt->detail, $ctx['at'], 0, null, $alt->meta);
            $node['children'] = $this->expandList($alt->children, [...$ctx, 'depth' => $ctx['depth'] + 1]);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Alternatives that lead to exactly the same place become one, labelled with all of them.
     *
     * @param  list<array<string, mixed>>  $alts
     * @return list<array<string, mixed>>
     */
    private function mergeAlternatives(array $alts): array
    {
        $groups = [];
        $order = [];

        foreach ($alts as $alt) {
            if ($alt['kind'] !== 'alt') {
                $groups['__'.count($order)] = [$alt];
                $order[] = '__'.count($order);

                continue;
            }

            $sig = md5(implode('|', array_map(fn ($c) => $this->signature($c), $alt['children'])));

            if (! isset($groups[$sig])) {
                $order[] = $sig;
            }

            $groups[$sig][] = $alt;
        }

        $merged = [];

        foreach ($order as $sig) {
            $group = $groups[$sig];
            $first = $group[0];

            if (count($group) > 1) {
                $first['label'] = implode(' · ', array_map(fn ($a) => $a['label'], $group));
                $first['count'] = count($group);
            }

            $merged[] = $first;
        }

        return $merged;
    }

    /** @param  array<string, mixed>  $node */
    private function signature(array $node): string
    {
        return md5(json_encode([
            $node['kind'], $node['label'], $node['detail'], $node['after'],
            $node['object']['type'] ?? null, $node['object']['id'] ?? null,
            array_map(fn ($c) => $this->signature($c), $node['children']),
        ]));
    }

    /**
     * Hand an event on to the objects the map leads to.
     *
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string, person: SimPerson}  $ctx
     * @return list<array<string, mixed>>
     */
    private function deliver(SimEvent $event, array $ctx): array
    {
        $from = $event->from ? $event->from['type'].':'.$event->from['id'] : $ctx['here'];
        $targets = [];

        if ($event->target !== null) {
            // A call names its receiver: it is entered even when the map does not show it, because the
            // call is real. Whoever may not see it is told so by the receiver's own right.
            $targets[$event->target['type'].':'.$event->target['id']] = [$event->target['type'], $event->target['id'], null, null];
        } elseif ($from !== null) {
            foreach ($this->out[$from] ?? [] as $edge) {
                if ($event->via !== [] && ! in_array($edge['kind'], $event->via, true)) {
                    continue;
                }

                $targets[$edge['to_type'].':'.$edge['to_id']] ??= [$edge['to_type'], $edge['to_id'], $edge['kind'], $edge['label'] ?? null];
            }
        }

        $nodes = [];
        $total = count($targets);
        $i = 0;

        foreach ($targets as [$type, $id, $kind, $edgeLabel]) {
            if (! $this->room() || $ctx['depth'] > self::MAX_DEPTH) {
                $this->cut += $total - $i;
                $nodes[] = $this->more($total - $i, $ctx['at']);

                break;
            }

            $i++;
            $arrives = $event->from === null && $ctx['here'] !== null ? $event->from(...array_values($this->split($ctx['here']))) : $event;
            $node = $this->enter($type, $id, $arrives, $ctx, $kind);

            if ($node !== null) {
                if (! isset($this->present[$type.':'.$id])) {
                    $node['meta']['off_map'] = true;
                }

                if ($edgeLabel !== null) {
                    $node['meta']['edge_label'] = $edgeLabel;
                }

                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Why a card is part of the way, in words: what it was reached by.
     *
     * @param  array<string, mixed>  $node  an `enter` node
     */
    private function whyEnter(array $node): string
    {
        $event = (string) ($node['meta']['event'] ?? '');
        $edge = (string) ($node['meta']['edge_label'] ?? $node['meta']['edge'] ?? '');
        $how = match (true) {
            $event === SimEvent::REQUESTED => __('brand-context::messages.sim_how_requested'),
            $event === SimEvent::PURCHASED => __('brand-context::messages.sim_how_purchased'),
            $event === SimEvent::STARTED => __('brand-context::messages.sim_how_started'),
            $edge !== '' => __('brand-context::messages.sim_how_edge', ['edge' => $edge]),
            default => __('brand-context::messages.sim_how_reached'),
        };

        return __('brand-context::messages.sim_why_enter', ['type' => (string) $node['detail'], 'name' => (string) $node['label'], 'how' => $how]);
    }

    /** @return array{type: string, id: string} */
    private function split(string $key): array
    {
        [$type, $id] = explode(':', $key, 2);

        return ['type' => $type, 'id' => $id];
    }

    /** @return array{label: string, type_label: string} */
    private function labelOf(string $type, string $id): array
    {
        $key = $type.':'.$id;

        if (isset($this->labels[$key])) {
            return $this->labels[$key];
        }

        $lookup = $this->registry->lookup($type, $id);

        return $this->labels[$key] = [
            'label' => $lookup->status === MapLookup::FOUND && $lookup->object ? $lookup->object->label : $id,
            'type_label' => $this->registry->types()[$type] ?? $type,
        ];
    }

    /**
     * Whether another node may be started. Every node built is counted ({@see node()}), so the
     * budget can be overdrawn by the few a single step makes, never by more.
     *
     * @phpstan-impure
     */
    private function room(): bool
    {
        return $this->budget > 0;
    }

    /**
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function node(string $kind, string $label, ?string $detail, int $at, int $after, ?array $object, array $meta = []): array
    {
        $this->budget--;

        return [
            'id' => null,
            'kind' => $kind,
            'label' => $label,
            'detail' => $detail,
            'at' => $at,
            'after' => $after,
            'object' => $object,
            'meta' => $meta,
            'count' => 1,
            'owner' => null,
            'children' => [],
        ];
    }

    /** @param  array<string, mixed>|null  $object */
    private function leaf(string $kind, string $label, int $at, ?array $object = null): array
    {
        return $this->node($kind, $label, null, $at, 0, $object);
    }

    private function more(int $ways, int $at): array
    {
        return $this->node('more', trans_choice('brand-context::messages.sim_more', $ways, ['count' => $ways]), null, $at, 0, null, ['ways' => $ways]);
    }
}
