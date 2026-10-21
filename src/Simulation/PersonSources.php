<?php

namespace Goldnead\BrandContext\Simulation;

use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The places a dry run can copy a real person from (see {@see ProvidesSimPersons}).
 *
 * An addon registers its source in `boot()`, like a map provider; an addon that is not installed is simply
 * not here. Every call is isolated: a source that throws or that the user may not use costs only its own part
 * of the copy, and what is logged is the source and the exception class, never what it was asked about.
 *
 * Nothing is written and nothing is kept: {@see state()} builds a {@see SimPerson} out of what the sources
 * answer, in memory.
 */
class PersonSources
{
    /** @var array<string, class-string<ProvidesSimPersons>> */
    protected array $sources = [];

    /** @var array<string, ProvidesSimPersons> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /** @param  class-string<ProvidesSimPersons>  $source */
    public function register(string $source): void
    {
        if (! is_subclass_of($source, ProvidesSimPersons::class)) {
            throw new InvalidArgumentException("{$source} does not implement ".ProvidesSimPersons::class.'.');
        }

        $id = $this->container->make($source)->personSourceId();

        if (isset($this->sources[$id]) && $this->sources[$id] !== $source) {
            throw new InvalidArgumentException("Person source [{$id}] is already registered by {$this->sources[$id]}.");
        }

        $this->sources[$id] = $source;
        unset($this->resolved[$id]);
    }

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    /**
     * People from every source this user may read, for a picker. Keys carry the source, so a key is only
     * ever handed back to the source it came from.
     *
     * @return list<array{key: string, label: string}>
     */
    public function search(string $query, $user, int $limit = 8): array
    {
        $found = [];

        foreach (array_keys($this->sources) as $id) {
            foreach ($this->guard($id, 'search', fn (ProvidesSimPersons $s) => $s->personsAuthorized($user) ? $s->searchPersons($query, $limit) : []) ?? [] as $row) {
                if (is_array($row) && is_string($row['key'] ?? null) && is_string($row['label'] ?? null)) {
                    $found[] = ['key' => $id.'|'.$row['key'], 'label' => mb_strimwidth($row['label'], 0, 160, '…')];
                }
            }
        }

        return array_slice($found, 0, $limit);
    }

    /**
     * The test person a key stands for: what every source this user may read says about her, put together.
     * Null when no source knows the key.
     */
    public function state(string $key, $user): ?SimPerson
    {
        return $this->describe($key, $user)['person'] ?? null;
    }

    /**
     * The same, and which sources did not speak: the ones this user may not read and the ones that failed. A copy
     * that is silent about a part reads as "she has no access", where the truth is "not read": the screen says so.
     *
     * @return array{person: SimPerson, missing: list<string>}|null
     */
    public function describe(string $key, $user): ?array
    {
        [$origin, $own] = array_pad(explode('|', $key, 2), 2, '');

        if ($own === '' || ! isset($this->sources[$origin])) {
            return null;
        }

        $identity = $this->guard($origin, 'identify', fn (ProvidesSimPersons $s) => $s->personsAuthorized($user) ? $s->identifyPerson($own) : null);

        if (! is_array($identity)) {
            return null;
        }

        $traits = [];
        $access = [];
        $ran = [];
        $missing = [];

        foreach (array_keys($this->sources) as $id) {
            $allowed = $this->guard($id, 'authorize', fn (ProvidesSimPersons $s) => (bool) $s->personsAuthorized($user));
            $facet = $allowed === true ? $this->guard($id, 'facet', fn (ProvidesSimPersons $s) => $s->personFacet($identity)) : null;

            if (! is_array($facet)) {
                $missing[] = $id;

                continue;
            }

            $traits = [...$traits, ...(is_array($facet['traits'] ?? null) ? $facet['traits'] : [])];
            $access = [...$access, ...$this->words($facet['access'] ?? [])];
            $ran = [...$ran, ...$this->words($facet['ran'] ?? [])];

            // Asked not to be written to: the one fact every later mail of a marketing kind must respect.
            if (($facet['unsubscribed'] ?? false) === true) {
                $traits['marketing.unsubscribed'] = true;
            }
        }

        return ['person' => SimPerson::known($traits, $access, $ran), 'missing' => $missing];
    }

    /** @return list<string> */
    private function words(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter(array_map(fn ($w) => is_string($w) ? mb_substr(trim($w), 0, 191) : '', $list))) : [];
    }

    private function guard(string $id, string $what, callable $call): mixed
    {
        try {
            return $call($this->container->make($this->sources[$id]));
        } catch (Throwable $e) {
            // The source and what failed. What it was asked about is a person: not logged.
            Log::warning("Person source [{$id}] failed in {$what}(): ".$e::class);

            return null;
        }
    }
}
