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
     * @param  list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}>  $edges
     * @param  mixed  $user  the Control Panel user whose rights apply
     * @return array{roots: list<array<string, mixed>>, stats: array{nodes: int, leaves: int, cut: int, unreached: list<string>}}
     */
    public function tree(array $nodes, array $edges, ?SimPerson $person = null, mixed $user = null, ?CarbonImmutable $start = null): array
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

            if (! isset($this->present[$from], $this->present[$to]) || $from === $to) {
                continue;
            }

            $this->out[$from][] = $e;
            $incoming[$to] = true;
        }

        $roots = [];

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
                $node = $this->enter($n['type'], (string) $n['id'], $event, $this->context());

                if ($node !== null) {
                    $roots[] = $node;
                }
            }
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

    /** @return array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string} */
    private function context(): array
    {
        return ['path' => [], 'bought' => [], 'at' => 0, 'depth' => 0, 'here' => null];
    }

    /**
     * An object receives an event.
     *
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
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

        if (! $this->registry->simulationAllowed($type, $id, $this->user)) {
            $node['children'][] = $this->leaf('denied', __('brand-context::messages.sim_denied'), $ctx['at']);

            return $node;
        }

        $effects = $this->registry->canReact($type)
            ? $this->registry->react($type, $id, $event, $this->person, $this->start->addSeconds($ctx['at']))
            : null;

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
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
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
        }

        return $nodes;
    }

    /**
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
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

        if ($effect->kind === Effect::FORK) {
            $node['children'] = $this->mergeAlternatives($this->alternatives($effect->children, $inner));

            return [$node];
        }

        $children = $this->expandList($effect->children, $inner);

        if ($effect->kind === Effect::MAIL) {
            $children = $this->mailFork($children, $inner);
        }

        $node['children'] = $children;

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
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
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
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
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
     * @param  array{path: list<string>, bought: list<string>, at: int, depth: int, here: ?string}  $ctx
     * @return list<array<string, mixed>>
     */
    private function deliver(SimEvent $event, array $ctx): array
    {
        $from = $event->from ? $event->from['type'].':'.$event->from['id'] : $ctx['here'];
        $targets = [];

        if ($event->target !== null) {
            $key = $event->target['type'].':'.$event->target['id'];

            if (isset($this->present[$key])) {
                $targets[$key] = [$event->target['type'], $event->target['id'], null];
            }
        } elseif ($from !== null) {
            foreach ($this->out[$from] ?? [] as $edge) {
                if ($event->via !== [] && ! in_array($edge['kind'], $event->via, true)) {
                    continue;
                }

                $targets[$edge['to_type'].':'.$edge['to_id']] ??= [$edge['to_type'], $edge['to_id'], $edge['kind']];
            }
        }

        $nodes = [];
        $total = count($targets);
        $i = 0;

        foreach ($targets as [$type, $id, $kind]) {
            if (! $this->room() || $ctx['depth'] > self::MAX_DEPTH) {
                $this->cut += $total - $i;
                $nodes[] = $this->more($total - $i, $ctx['at']);

                break;
            }

            $i++;
            $arrives = $event->from === null && $ctx['here'] !== null ? $event->from(...array_values($this->split($ctx['here']))) : $event;
            $node = $this->enter($type, $id, $arrives, $ctx, $kind);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
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
