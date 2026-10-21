<?php

namespace Goldnead\BrandContext\Simulation;

/**
 * Something the test person does after a mail, chosen by the operator: buys, clicks a link, unsubscribes.
 *
 * It is part of the simulation's input and nothing else. The planner turns it into lines of the person's
 * way ("buys", "clicks", "unsubscribes") and into what follows from them (a purchase is handed to the
 * product, an unsubscribe silences marketing mails after it). Nothing is bought, clicked or unsubscribed.
 */
final class SimAction
{
    public const BUY = 'buy';

    public const CLICK = 'click';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const TYPES = [self::BUY, self::CLICK, self::UNSUBSCRIBE];

    /** Most actions one person carries: an operator clicks a few, not a hundred. */
    public const MAX = 12;

    /**
     * @param  string  $mail  the mail the action follows, as {@see Simulator::mailKey()} names it
     * @param  string|null  $product  `type:id` of the product that is bought (BUY)
     * @param  string|null  $url  the link that is clicked (CLICK)
     * @param  int  $afterSeconds  how long after the mail she does it
     */
    public function __construct(
        public readonly string $type,
        public readonly string $mail,
        public readonly ?string $product = null,
        public readonly ?string $url = null,
        public readonly int $afterSeconds = 0,
    ) {}

    /**
     * An action from a request, or null when it is not one: unknown type, no mail, a purchase without a
     * product. Lengths are capped; the delay is at most a year.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = (string) ($data['type'] ?? '');
        $mail = mb_substr((string) ($data['mail'] ?? ''), 0, 300);

        if (! in_array($type, self::TYPES, true) || $mail === '') {
            return null;
        }

        $product = isset($data['product']) && is_string($data['product']) && $data['product'] !== '' ? mb_substr($data['product'], 0, 300) : null;
        $url = isset($data['url']) && is_string($data['url']) && $data['url'] !== '' ? mb_substr($data['url'], 0, 1000) : null;

        if ($type === self::BUY && $product === null) {
            return null;
        }

        return new self($type, $mail, $type === self::BUY ? $product : null, $type === self::CLICK ? $url : null, max(0, min(365 * 86400, (int) ($data['after_seconds'] ?? 0))));
    }

    /** @return array{type: string, mail: string, product: ?string, url: ?string, after_seconds: int} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'mail' => $this->mail, 'product' => $this->product, 'url' => $this->url, 'after_seconds' => $this->afterSeconds];
    }
}
