<?php

namespace Goldnead\BrandContext\Map;

use Carbon\CarbonImmutable;

/**
 * The span a map number is counted over.
 *
 * One period for every card on a map, chosen by the screen and handed to each
 * provider unchanged. Two cards that count over different spans next to each
 * other are two numbers nobody may compare, so the span is not the provider's
 * to pick.
 */
final class MapPeriod
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $days,
    ) {}

    /** The last `$days` days, ending now. */
    public static function lastDays(int $days = 30): self
    {
        $days = max(1, $days);
        $now = CarbonImmutable::now();

        return new self($now->subDays($days), $now, $days);
    }

    /** Short wording for the card footer, e.g. "last 30 days". */
    public function label(): string
    {
        return __('brand-context::messages.map_period_days', ['days' => $this->days]);
    }
}
