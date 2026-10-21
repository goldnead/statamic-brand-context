<?php

namespace Goldnead\BrandContext\Simulation;

/**
 * Something that happens to a person in a dry run: she asks for a lead magnet, buys a product,
 * is given access. It is a description, never a Laravel event: nothing listens to it and nothing
 * is dispatched.
 *
 * An event travels along the map. The object that produced it ({@see $from}) hands it to the
 * objects its map edges lead to ({@see $via} narrows the edge kinds, {@see $target} names one
 * receiver), and each receiver answers with what it would do.
 */
final class SimEvent
{
    /** The person reaches the object over a map connection. */
    public const ENTERED = 'entered';

    /** The object starts on its own (its trigger, a schedule): the root of a path. */
    public const STARTED = 'started';

    /** A lead magnet was asked for. */
    public const REQUESTED = 'requested';

    /** The person bought a product. */
    public const PURCHASED = 'purchased';

    /** The person was given access to something. */
    public const GRANTED = 'granted';

    /**
     * @param  array{type: string, id: string}|null  $from  object that produced the event
     * @param  array{type: string, id: string}|null  $target  deliver to this object only
     * @param  list<string>  $via  edge kinds the event may travel; empty means any
     * @param  array<string, mixed>  $data  small facts the receiver needs (a grant slug, a product handle)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?array $from = null,
        public readonly ?array $target = null,
        public readonly array $via = [],
        public readonly array $data = [],
    ) {}

    /** The same event as seen from another object: used when the planner hands it on. */
    public function from(string $type, string $id): self
    {
        return new self($this->type, ['type' => $type, 'id' => $id], $this->target, $this->via, $this->data);
    }
}
