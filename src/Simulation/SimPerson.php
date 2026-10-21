<?php

namespace Goldnead\BrandContext\Simulation;

/**
 * The person a dry run is about: a made-up test person, or the state of a real one copied out of
 * the CRM, read only.
 *
 * An unknown person ({@see unknown()}) is the "every way" mode: nothing is known, so a provider
 * that meets a condition answers with a fork instead of choosing. A known person has an answer
 * for every condition, so the same provider returns one straight path.
 *
 * The object is immutable and holds no name, no email, no id of a real record: only the traits,
 * the accesses and the automations she has already been through, which is what the conditions
 * read. Nothing here is logged.
 */
final class SimPerson
{
    /**
     * @param  array<string, mixed>  $traits  tags and attributes, key => value
     * @param  list<string>  $access  grant slugs she already holds
     * @param  list<string>  $ran  handles of automations she has already been through
     */
    private function __construct(
        public readonly bool $known,
        public readonly array $traits = [],
        public readonly array $access = [],
        public readonly array $ran = [],
    ) {}

    /** Nothing is known: providers fork at every condition. */
    public static function unknown(): self
    {
        return new self(false);
    }

    /**
     * A person whose state is given.
     *
     * @param  array<string, mixed>  $traits
     * @param  list<string>  $access
     * @param  list<string>  $ran
     */
    public static function known(array $traits = [], array $access = [], array $ran = []): self
    {
        return new self(true, $traits, array_values(array_unique($access)), array_values(array_unique($ran)));
    }

    public function isKnown(): bool
    {
        return $this->known;
    }

    public function trait(string $key, mixed $default = null): mixed
    {
        return $this->traits[$key] ?? $default;
    }

    public function hasAccess(string $slug): bool
    {
        return in_array($slug, $this->access, true);
    }

    public function hasRun(string $handle): bool
    {
        return in_array($handle, $this->ran, true);
    }

    public function withTrait(string $key, mixed $value): self
    {
        return new self($this->known, [...$this->traits, $key => $value], $this->access, $this->ran);
    }

    public function withAccess(string $slug): self
    {
        return new self($this->known, $this->traits, array_values(array_unique([...$this->access, $slug])), $this->ran);
    }

    public function withRun(string $handle): self
    {
        return new self($this->known, $this->traits, $this->access, array_values(array_unique([...$this->ran, $handle])));
    }
}
