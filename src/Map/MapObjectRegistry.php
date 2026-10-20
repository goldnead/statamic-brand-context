<?php

namespace Goldnead\BrandContext\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapConnections;
use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Which addons announced objects a map can point at.
 *
 * Same idea as the settings registry: an addon registers in its `boot()`,
 * nothing scans the disk, and an addon that is not installed simply has no
 * type here. A provider that throws costs its own card, never the screen:
 * every call is isolated and logged.
 */
class MapObjectRegistry
{
    /** @var array<string, class-string<ProvidesMapObjects>> */
    protected array $providers = [];

    /** @var array<string, ProvidesMapObjects> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<ProvidesMapObjects>  $provider
     */
    public function register(string $provider): void
    {
        if (! is_subclass_of($provider, ProvidesMapObjects::class)) {
            throw new InvalidArgumentException("{$provider} does not implement ".ProvidesMapObjects::class.'.');
        }

        $type = $this->container->make($provider)->type();

        if (isset($this->providers[$type]) && $this->providers[$type] !== $provider) {
            throw new InvalidArgumentException("Map type [{$type}] is already registered by {$this->providers[$type]}.");
        }

        $this->providers[$type] = $provider;
        unset($this->resolved[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->providers[$type]);
    }

    /** @return array<string, string> type => label, in registration order */
    public function types(): array
    {
        $types = [];

        foreach (array_keys($this->providers) as $type) {
            $types[$type] = $this->guard($type, 'typeLabel', fn (ProvidesMapObjects $p) => $p->typeLabel()) ?? $type;
        }

        return $types;
    }

    /** @return array<int, MapObject> */
    public function objects(string $type, ?string $query = null, int $limit = 50): array
    {
        return $this->guard($type, 'objects', fn (ProvidesMapObjects $p) => array_values($p->objects($query, $limit))) ?? [];
    }

    public function lookup(string $type, string $id): MapLookup
    {
        if (! $this->has($type)) {
            return MapLookup::unavailable();
        }

        $object = null;
        $failed = false;

        try {
            $object = $this->provider($type)->find($id);
        } catch (Throwable $e) {
            $failed = true;
            $this->report($type, 'find', $e);
        }

        if ($failed) {
            return MapLookup::unavailable();
        }

        return $object ? MapLookup::found($object) : MapLookup::missing();
    }

    /**
     * Every derived edge of every provider that offers them. A provider that
     * throws costs only its own edges.
     *
     * @return array<string, MapEdge> keyed by {@see MapEdge::key()}
     */
    public function edges(): array
    {
        $edges = [];

        foreach (array_keys($this->providers) as $type) {
            $list = $this->guard($type, 'edges', function (ProvidesMapObjects $p) {
                return $p instanceof ProvidesMapEdges ? iterator_to_array($p->edges(), false) : [];
            }) ?? [];

            foreach ($list as $edge) {
                if ($edge instanceof MapEdge) {
                    $edges[$edge->key()] = $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * Every pair a provider lets the map write. Providers are found with
     * `method_exists` (see {@see ProvidesMapConnections}), a provider that
     * throws costs only its own rules, and the first rule for a
     * (from, to, kind) wins.
     *
     * @return list<array{from_type: string, to_type: string, kind: string, label: string, owner: string}>
     */
    public function connectionRules(): array
    {
        $rules = [];
        $seen = [];

        foreach (array_keys($this->providers) as $type) {
            $list = $this->guard($type, 'connectionRules', function (ProvidesMapObjects $p) {
                return method_exists($p, 'connectionRules') ? $p->connectionRules() : [];
            }) ?? [];

            foreach ($list as $rule) {
                if (! is_array($rule) || ! isset($rule['from_type'], $rule['to_type'], $rule['kind'])) {
                    continue;
                }

                $key = "{$rule['from_type']}>{$rule['kind']}>{$rule['to_type']}";

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rules[] = [
                    'from_type' => (string) $rule['from_type'],
                    'to_type' => (string) $rule['to_type'],
                    'kind' => (string) $rule['kind'],
                    'label' => (string) ($rule['label'] ?? $rule['kind']),
                    'owner' => $type,
                ];
            }
        }

        return $rules;
    }

    /** The one rule for a pair, or null when the map may not connect it. */
    public function ruleFor(string $fromType, string $toType): ?array
    {
        foreach ($this->connectionRules() as $rule) {
            if ($rule['from_type'] === $fromType && $rule['to_type'] === $toType) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * What a connect or disconnect would do, in plain words.
     *
     * @param  array<string, string>  $c  from_type, from_id, to_type, to_id, optional kind
     * @return array{kind: string, consequence: string, before: string, after: string}
     *
     * @throws InvalidArgumentException unknown pair or action
     * @throws \DomainException the provider says it is not possible (passed through)
     */
    public function previewConnection(array $c, string $action): array
    {
        [$rule, $c] = $this->ruleAndConnection($c, $action);

        $preview = $this->connectionProvider($rule['owner'])->connectionPreview($c, $action);

        return [
            'kind' => $c['kind'],
            'consequence' => (string) ($preview['consequence'] ?? ''),
            'before' => (string) ($preview['before'] ?? ''),
            'after' => (string) ($preview['after'] ?? ''),
        ];
    }

    /**
     * Writes a connection: rule, permission, preview, write, audit.
     *
     * @param  array<string, string>  $c
     * @param  mixed  $user  Statamic user or null
     * @return array{kind: string, consequence: string, before: string, after: string, undo: array<string, string>}
     *
     * @throws InvalidArgumentException unknown pair or action
     * @throws AuthorizationException the user may not write it
     * @throws \DomainException not possible right now
     */
    public function applyConnection(array $c, string $action, $user): array
    {
        [$rule, $c] = $this->ruleAndConnection($c, $action);
        $provider = $this->connectionProvider($rule['owner']);

        if (! $provider->connectionAuthorized($c, $user)) {
            throw new AuthorizationException;
        }

        $preview = $provider->connectionPreview($c, $action);

        try {
            $written = $action === 'connect' ? $provider->connect($c) : $provider->disconnect($c);
        } catch (Throwable $e) {
            Log::error("Map provider [{$rule['owner']}] failed in {$action}(): {$e->getMessage()}", ['exception' => $e]);

            throw $e;
        }

        $result = [
            'kind' => $c['kind'],
            'consequence' => (string) ($preview['consequence'] ?? ''),
            'before' => (string) ($written['before'] ?? $preview['before'] ?? ''),
            'after' => (string) ($written['after'] ?? $preview['after'] ?? ''),
        ];

        $this->audit($action, $c, $result, $user);

        $result['undo'] = ['action' => $action === 'connect' ? 'disconnect' : 'connect'] + $c;

        return $result;
    }

    public function metric(string $type, string $id, MapPeriod $period): ?MapMetric
    {
        return $this->guard($type, 'metric', fn (ProvidesMapObjects $p) => $p->metric($id, $period));
    }

    /**
     * @param  array<string, string>  $c
     * @return array{0: array, 1: array<string, string>} rule and the connection with its kind filled in
     */
    protected function ruleAndConnection(array $c, string $action): array
    {
        if (! in_array($action, ['connect', 'disconnect'], true)) {
            throw new InvalidArgumentException("Unknown connection action [{$action}].");
        }

        $from = (string) ($c['from_type'] ?? '');
        $to = (string) ($c['to_type'] ?? '');
        $rule = $this->ruleFor($from, $to);

        if ($rule === null) {
            throw new InvalidArgumentException("Map pair [{$from}] to [{$to}] cannot be connected.");
        }

        return [$rule, [
            'from_type' => $from,
            'from_id' => (string) ($c['from_id'] ?? ''),
            'to_type' => $to,
            'to_id' => (string) ($c['to_id'] ?? ''),
            'kind' => (string) ($c['kind'] ?? $rule['kind']),
        ]];
    }

    /** The audit facade of statamic-activity, or null when that addon is not installed. */
    protected function activityFacade(): ?string
    {
        $facade = 'Goldnead\\Activity\\Facades\\Activity';

        return class_exists($facade) ? $facade : null;
    }

    /**
     * Who, when, what, before and after. A failing audit is reported and
     * never undoes the write nor kills the request.
     *
     * @param  array<string, string>  $c
     * @param  array<string, string>  $result
     */
    protected function audit(string $action, array $c, array $result, $user): void
    {
        $event = $action === 'connect' ? 'map.connection_made' : 'map.connection_removed';
        $who = [
            'id' => $user && method_exists($user, 'id') ? (string) $user->id() : null,
            'email' => $user && method_exists($user, 'email') ? (string) $user->email() : null,
            'name' => $user && method_exists($user, 'name') ? (string) $user->name() : null,
        ];
        $facts = [
            'user' => $who,
            'at' => now()->toIso8601String(),
            'from_type' => $c['from_type'],
            'from_id' => $c['from_id'],
            'to_type' => $c['to_type'],
            'to_id' => $c['to_id'],
            'kind' => $c['kind'],
            'consequence' => $result['consequence'],
            'before' => $result['before'],
            'after' => $result['after'],
        ];

        try {
            $facade = $this->activityFacade();

            if ($facade !== null) {
                $facade::record($event, ['actor' => $user, 'properties' => $facts]);
            } else {
                Log::info($event, $facts);
            }
        } catch (Throwable $e) {
            Log::warning("Map audit [{$event}] failed: {$e->getMessage()}", ['exception' => $e]);
        }
    }

    /**
     * A rule only exists for a provider that has the five methods, so the
     * duck-typed provider can be treated as the documented interface. No
     * native return type: the provider need not implement it.
     *
     * @return ProvidesMapConnections
     */
    protected function connectionProvider(string $type)
    {
        return $this->provider($type); // @phpstan-ignore return.type
    }

    protected function provider(string $type): ProvidesMapObjects
    {
        return $this->resolved[$type] ??= $this->container->make($this->providers[$type]);
    }

    protected function guard(string $type, string $method, callable $call): mixed
    {
        if (! $this->has($type)) {
            return null;
        }

        try {
            return $call($this->provider($type));
        } catch (Throwable $e) {
            $this->report($type, $method, $e);

            return null;
        }
    }

    protected function report(string $type, string $method, Throwable $e): void
    {
        Log::warning("Map provider [{$type}] failed in {$method}(): {$e->getMessage()}", ['exception' => $e]);
    }
}
