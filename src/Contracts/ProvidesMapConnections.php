<?php

namespace Goldnead\BrandContext\Contracts;

use DomainException;
use Goldnead\BrandContext\Map\MapEdge;

/**
 * Optional third part of {@see ProvidesMapObjects}: the connections an addon
 * lets the map write (drag an arrow from card A to card B).
 *
 * Documentation interface. The registry finds providers with `method_exists`,
 * so an addon does not have to reference this class (and keeps running against
 * an older brand-context). Same signatures, plain arrays.
 *
 * A connection `$c` is always
 * `['from_type' => string, 'from_id' => string, 'to_type' => string, 'to_id' => string, 'kind' => string]`,
 * `kind` being the key of {@see MapEdge::$kind}.
 *
 * An addon only writes into an empty field (or adds a missing list entry) and
 * never overwrites another value. That keeps disconnect() the exact inverse of
 * connect(), without an undo store.
 */
interface ProvidesMapConnections
{
    /**
     * The pairs this addon can write.
     *
     * @return list<array{from_type: string, to_type: string, kind: string, label: string}> label is a short translated verb
     */
    public function connectionRules(): array;

    /**
     * May this user write it? `$user` is a Statamic user or null. Check BOTH
     * ends: the permission to edit the source and the one to edit the target.
     * The registry asks this for the preview as well as for the write.
     *
     * @param  array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}  $c
     */
    public function connectionAuthorized(array $c, $user): bool;

    /**
     * Checks that it is writable right now and describes the effect in plain
     * words. `$action` is `connect` or `disconnect`.
     *
     * @param  array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}  $c
     * @return array{consequence: string, before: string, after: string}
     *
     * @throws DomainException with a short translated reason when it is not possible
     */
    public function connectionPreview(array $c, string $action): array;

    /**
     * Writes for real. Re-reads the field and re-checks with connectionPreview
     * right before it writes (compare and set): when the field is no longer
     * what the user saw, throw a DomainException and write nothing.
     *
     * @param  array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}  $c
     * @return array{before: string, after: string}
     */
    public function connect(array $c): array;

    /**
     * Undoes exactly what connect() wrote. When the removed thing is more than
     * a value (a row with its own id, position and fields), return it as
     * `restore`: the map hands it back to connect() as `$c['restore']` when the
     * user undoes, and connect() puts back exactly that.
     *
     * @param  array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string}  $c
     * @return array{before: string, after: string, restore?: array<string, mixed>}
     */
    public function disconnect(array $c): array;
}
