<?php

namespace Goldnead\BrandContext\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
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

    public function metric(string $type, string $id, MapPeriod $period): ?MapMetric
    {
        return $this->guard($type, 'metric', fn (ProvidesMapObjects $p) => $p->metric($id, $period));
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
