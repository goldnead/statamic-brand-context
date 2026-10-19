<?php

namespace Goldnead\BrandContext\Map;

/**
 * A connection between two map objects that an addon can read from its own data.
 *
 * An edge is never stored by the addon: it is computed from the thing that
 * already says it (an automation that waits for another, an offer that lists a
 * product). `$derivedFrom` names that thing in words an operator can follow, so
 * the map can answer "why is there a line here".
 *
 * The target may not exist. An automation that waits for a handle nobody owns
 * still yields an edge, with `$toLabel` carrying the text that was written: the
 * map draws the broken reference instead of hiding it.
 */
final class MapEdge
{
    public function __construct(
        public readonly string $fromType,
        public readonly string $fromId,
        public readonly string $toType,
        public readonly string $toId,
        /** Stable key of the relation, e.g. `waits_for`, `starts`, `sends`, `offers`, `grants`. */
        public readonly string $kind,
        /** Short wording shown on the edge, e.g. "waits for". Translated by the provider. */
        public readonly string $label,
        /** Where the relation was read from, e.g. "wait_for_automation step of automation x". */
        public readonly string $derivedFrom,
        /** True for a relation that is a wait, not a hand-over of people. */
        public readonly bool $dashed = false,
        /** What was written where the target should be, shown if the target is gone. */
        public readonly ?string $toLabel = null,
    ) {}

    /** Identity of the edge inside one map: the same relation twice is one line. */
    public function key(): string
    {
        return "{$this->fromType}:{$this->fromId}>{$this->kind}>{$this->toType}:{$this->toId}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'from_type' => $this->fromType,
            'from_id' => $this->fromId,
            'to_type' => $this->toType,
            'to_id' => $this->toId,
            'kind' => $this->kind,
            'label' => $this->label,
            'derived_from' => $this->derivedFrom,
            'dashed' => $this->dashed,
            'to_label' => $this->toLabel,
        ];
    }
}
