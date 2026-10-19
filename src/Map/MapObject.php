<?php

namespace Goldnead\BrandContext\Map;

/**
 * A thing in one addon that a map can point at.
 *
 * `$enabled` is three-valued on purpose: true is on, false is off, null is
 * "this kind of object has no switch". A page entry is not "on", and showing
 * it as off would be a claim nobody made.
 */
final class MapObject
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $cpUrl = null,
        public readonly ?bool $enabled = null,
        public readonly ?string $detail = null,
        /**
         * Broken references the object itself carries, in words an operator can act on
         * ("step Tag 3 sends template x, which does not exist"). Empty when all is well.
         *
         * @var list<string>
         */
        public readonly array $problems = [],
    ) {}

    /** @return array{type: string, id: string, label: string, cp_url: ?string, enabled: ?bool, detail: ?string, problems: list<string>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label,
            'cp_url' => $this->cpUrl,
            'enabled' => $this->enabled,
            'detail' => $this->detail,
            'problems' => array_values($this->problems),
        ];
    }
}
