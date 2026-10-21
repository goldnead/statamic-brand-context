<?php

namespace Goldnead\BrandContext\Simulation;

/**
 * One line of what an object would do in a dry run. Nothing is executed: an effect only says
 * "a mail would go out", "a wait of two days", "access would be granted".
 *
 * Effects form a tree. `children` happen after this effect, all of them (a parallel split), except
 * under a {@see FORK}, where exactly one alternative ({@see ALT}) is taken. A provider that does not
 * know what a step would do answers {@see UNKNOWN} instead of guessing.
 */
final class Effect
{
    public const MAIL = 'mail';

    public const PAGE = 'page';

    public const ACCESS = 'access';

    public const TRAIT = 'trait';

    public const WAIT = 'wait';

    public const STOP = 'stop';

    /** The path splits; the children are {@see ALT} alternatives and exactly one is taken. */
    public const FORK = 'fork';

    /** One alternative of a {@see FORK}; the label says when it is taken. */
    public const ALT = 'alt';

    /** The object hands an event on to the objects its map connections lead to. */
    public const EMIT = 'emit';

    /** What happens here is not known; never a guess. */
    public const UNKNOWN = 'unknown';

    public const KINDS = [
        self::MAIL, self::PAGE, self::ACCESS, self::TRAIT, self::WAIT, self::STOP,
        self::FORK, self::ALT, self::EMIT, self::UNKNOWN,
    ];

    /**
     * @param  int  $afterSeconds  wait since the effect before it on this path
     * @param  array<string, mixed>  $meta  small facts for the screen (a node key, a template slug)
     * @param  list<Effect>  $children
     * @param  array{type: string, id: string}|null  $object  the object this effect belongs to, if not the reacting one
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $detail = null,
        public readonly int $afterSeconds = 0,
        public readonly array $meta = [],
        public readonly array $children = [],
        public readonly ?SimEvent $emits = null,
        public readonly ?array $object = null,
    ) {}

    /** @param  array<string, mixed>  $meta */
    public static function mail(string $subject, ?string $detail = null, int $after = 0, array $meta = []): self
    {
        return new self(self::MAIL, $subject, $detail, $after, $meta);
    }

    public static function wait(string $label, int $seconds, ?string $detail = null): self
    {
        return new self(self::WAIT, $label, $detail, max(0, $seconds));
    }

    public static function page(string $label, ?string $detail = null): self
    {
        return new self(self::PAGE, $label, $detail);
    }

    public static function access(string $label, ?string $detail = null, array $meta = []): self
    {
        return new self(self::ACCESS, $label, $detail, 0, $meta);
    }

    public static function stop(string $label): self
    {
        return new self(self::STOP, $label);
    }

    public static function unknown(string $label, ?string $detail = null): self
    {
        return new self(self::UNKNOWN, $label, $detail);
    }

    public static function emit(SimEvent $event, string $label = ''): self
    {
        return new self(self::EMIT, $label, null, 0, [], [], $event);
    }

    /**
     * Alternatives, exactly one of which is taken.
     *
     * @param  list<Effect>  $alternatives  {@see alt()}
     */
    public static function fork(string $label, array $alternatives): self
    {
        return new self(self::FORK, $label, null, 0, [], array_values($alternatives));
    }

    /** @param  list<Effect>  $children */
    public static function alt(string $when, array $children = []): self
    {
        return new self(self::ALT, $when, null, 0, [], array_values($children));
    }

    /** The same effect with other children. */
    public function withChildren(array $children): self
    {
        return new self($this->kind, $this->label, $this->detail, $this->afterSeconds, $this->meta, array_values($children), $this->emits, $this->object);
    }

    /** The same effect, said to belong to another object (a sub-automation's mail, say). */
    public function ofObject(string $type, string $id): self
    {
        return new self($this->kind, $this->label, $this->detail, $this->afterSeconds, $this->meta, $this->children, $this->emits, ['type' => $type, 'id' => $id]);
    }

    /**
     * A straight line: each effect is the child of the one before. The last one gets `$tail`.
     *
     * @param  list<Effect>  $effects
     * @param  list<Effect>  $tail
     * @return list<Effect> the first effect (or the tail when there are none)
     */
    public static function chain(array $effects, array $tail = []): array
    {
        $effects = array_values($effects);

        if ($effects === []) {
            return array_values($tail);
        }

        $rest = array_slice($effects, 1);
        $first = $effects[0];

        return [$first->withChildren([...$first->children, ...self::chain($rest, $tail)])];
    }
}
