<?php

namespace Goldnead\BrandContext\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapConnections;
use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
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
                    $edges[$edge->key()] = $edge->withOwner($type);
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
     * What a connect or disconnect would do, in plain words. Asks the same
     * permission question as {@see applyConnection()}: the preview names the
     * objects and the effect, so it is not for anyone who may not write it.
     *
     * @param  array<string, mixed>  $c  from_type, from_id, to_type, to_id, optional kind
     * @param  mixed  $user  Statamic user or null
     * @return array{kind: string, consequence: string, before: string, after: string}
     *
     * @throws InvalidArgumentException unknown pair or action
     * @throws AuthorizationException the user may not write it
     * @throws \DomainException the provider says it is not possible (passed through)
     */
    public function previewConnection(array $c, string $action, $user): array
    {
        [$rule, $c] = $this->ruleAndConnection($c, $action);
        $provider = $this->connectionProvider($rule['owner']);

        if (! $provider->connectionAuthorized($c, $user)) {
            throw new AuthorizationException;
        }

        $preview = $provider->connectionPreview($c, $action);

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
     * Compare and set: `$expectedBefore` is the `before` the user was shown
     * and confirmed. When the field says something else by now (a second tab,
     * a second user) nothing is written and a {@see ConnectionConflict} says
     * so. The provider re-reads the field again right before it writes.
     *
     * @param  array<string, mixed>  $c
     * @param  mixed  $user  Statamic user or null
     * @return array{kind: string, consequence: string, before: string, after: string, undo: array<string, mixed>}
     *
     * @throws InvalidArgumentException unknown pair or action
     * @throws AuthorizationException the user may not write it
     * @throws ConnectionConflict the field is no longer what the user confirmed
     * @throws \DomainException not possible right now
     */
    public function applyConnection(array $c, string $action, $user, ?string $expectedBefore = null): array
    {
        [$rule, $c] = $this->ruleAndConnection($c, $action);
        $provider = $this->connectionProvider($rule['owner']);

        if (! $provider->connectionAuthorized($c, $user)) {
            throw new AuthorizationException;
        }

        $preview = $provider->connectionPreview($c, $action);

        if ($expectedBefore !== null && (string) ($preview['before'] ?? '') !== $expectedBefore) {
            throw new ConnectionConflict("Expected [{$expectedBefore}], found [".($preview['before'] ?? '').'].');
        }

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

        unset($c['restore']);
        $result['undo'] = ['action' => $action === 'connect' ? 'disconnect' : 'connect'] + $c;

        // What disconnect() removed, so the undo can put back the exact thing.
        if (isset($written['restore']) && is_array($written['restore'])) {
            $result['undo']['restore'] = $written['restore'];
        }

        return $result;
    }

    public function metric(string $type, string $id, MapPeriod $period): ?MapMetric
    {
        return $this->guard($type, 'metric', fn (ProvidesMapObjects $p) => $p->metric($id, $period));
    }

    /**
     * What a provider shows in the card's panel beyond the one number: facts and short lists,
     * in the provider's own words. Optional: a provider without `details()` (or one that fails,
     * or does not know the id) answers null and the panel shows what it always showed.
     *
     * Blocks are `facts` (rows of label, value, optional link) and `list` (items with title,
     * optional meta and image, at most 12 shown, the rest counted in `more`). Anything else is
     * dropped, links that are not a path or http(s) URL are dropped, so the panel renders
     * whatever arrives without trusting it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $type, string $id, MapPeriod $period): ?array
    {
        $blocks = $this->guard($type, 'details', fn ($p) => method_exists($p, 'details') ? $p->details($id, $period) : null);

        if (! is_array($blocks)) {
            return null;
        }

        $clean = array_values(array_filter(array_map(fn ($b) => is_array($b) ? $this->cleanBlock($b) : null, $blocks)));

        return $clean === [] ? null : $clean;
    }

    /** @param  array<string, mixed>  $b */
    protected function cleanBlock(array $b): ?array
    {
        $title = $this->text($b['title'] ?? null);

        if (($b['kind'] ?? null) === 'facts') {
            $rows = [];
            foreach ((array) ($b['rows'] ?? []) as $row) {
                $label = is_array($row) ? $this->text($row['label'] ?? null) : null;
                $value = is_array($row) ? $this->text($row['value'] ?? null) : null;
                if ($label !== null && $value !== null) {
                    $rows[] = ['label' => $label, 'value' => $value, 'href' => $this->link($row['href'] ?? null)];
                }
            }

            return $rows === [] ? null : ['kind' => 'facts', 'title' => $title, 'rows' => $rows];
        }

        if (($b['kind'] ?? null) === 'list') {
            $items = [];
            foreach ((array) ($b['items'] ?? []) as $item) {
                $t = is_array($item) ? $this->text($item['title'] ?? null) : null;
                if ($t !== null) {
                    $items[] = ['title' => $t, 'meta' => $this->text($item['meta'] ?? null), 'image' => $this->link($item['image'] ?? null)];
                }
            }

            if ($items === []) {
                return null;
            }

            $shown = array_slice($items, 0, 12);
            $more = max(0, (int) ($b['more'] ?? 0)) + (count($items) - count($shown));

            return ['kind' => 'list', 'title' => $title, 'items' => $shown, 'more' => $more > 0 ? $more : null];
        }

        return null;
    }

    protected function text(mixed $v): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : mb_strimwidth($v, 0, 160, '…');
    }

    protected function link(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('~^(/(?!/)|https?://)~i', $v) ? $v : null;
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

        $connection = [
            'from_type' => $from,
            'from_id' => (string) ($c['from_id'] ?? ''),
            'to_type' => $to,
            'to_id' => (string) ($c['to_id'] ?? ''),
            'kind' => (string) ($c['kind'] ?? $rule['kind']),
        ];

        // Only a connect that undoes a disconnect carries it: the exact thing the disconnect removed.
        if ($action === 'connect' && isset($c['restore']) && is_array($c['restore'])) {
            $connection['restore'] = $c['restore'];
        }

        return [$rule, $connection];
    }

    /** The audit facade of statamic-activity, or null when that addon is not installed. */
    protected function activityFacade(): ?string
    {
        $facade = 'Goldnead\\Activity\\Facades\\Activity';

        return class_exists($facade) ? $facade : null;
    }

    /**
     * Who, when, what, before and after. Through statamic-activity when it is
     * installed and keeps the row, otherwise into the `map_connection_audits`
     * table, which outlives any log rotation. Only the user id is kept, never
     * an email or a name. A failing audit is reported and never undoes the
     * write nor kills the request.
     *
     * @param  array<string, string>  $c
     * @param  array<string, string>  $result
     */
    protected function audit(string $action, array $c, array $result, $user): void
    {
        $event = $action === 'connect' ? 'map.connection_made' : 'map.connection_removed';
        $userId = $user && method_exists($user, 'id') && $user->id() !== null ? (string) $user->id() : null;
        $facts = [
            'user_id' => $userId,
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

        $kept = false;

        try {
            $facade = $this->activityFacade();

            if ($facade !== null) {
                // `actor` is a typed Identity in statamic-activity, never a Statamic user.
                $identity = 'Goldnead\\IdentityContracts\\Identity';
                $attributes = ['properties' => $facts, 'user_id' => $userId];

                if ($userId !== null && class_exists($identity)) {
                    $attributes['actor'] = $identity::user($userId);
                }

                $kept = $facade::record($event, $attributes) !== null;
            }
        } catch (Throwable $e) {
            Log::warning("Map audit [{$event}] failed: {$e->getMessage()}", ['exception' => $e]);
        }

        if ($kept) {
            return;
        }

        try {
            DB::table('map_connection_audits')->insert([
                'event' => $event,
                'user_id' => $userId,
                'from_type' => $c['from_type'],
                'from_id' => $c['from_id'],
                'to_type' => $c['to_type'],
                'to_id' => $c['to_id'],
                'kind' => $c['kind'],
                'consequence' => $result['consequence'],
                'before' => $result['before'],
                'after' => $result['after'],
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The table is missing (migration not run) or the database is down: the log is the last record.
            Log::error("Map audit [{$event}] could not be stored: {$e->getMessage()}", $facts);
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
