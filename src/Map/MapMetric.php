<?php

namespace Goldnead\BrandContext\Map;

/**
 * The one number on a map card.
 *
 * `$what` names what is counted ("Requests", "Runs", "Started") and `$period`
 * says over which span. A bare number is never shown: without both, two cards
 * read as comparable when one counts visits and the other counts mails.
 */
final class MapMetric
{
    public function __construct(
        public readonly int|float $value,
        public readonly string $what,
        public readonly MapPeriod $period,
    ) {}

    /** @return array{value: int|float, what: string, period: string} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'what' => $this->what,
            'period' => $this->period->label(),
        ];
    }
}
