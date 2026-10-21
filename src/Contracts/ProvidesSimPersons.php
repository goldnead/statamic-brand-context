<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Simulation\PersonSources;

/**
 * A place that knows something about real people, for the dry run's "as it is for person X".
 *
 * The test person of the Probelauf can be copied from a real one: her tags and traits, the access she holds,
 * the automations she has been through. No addon owns all of that, so each one that knows part of it offers
 * that part through {@see PersonSources}:
 *
 * - the CRM finds a person (`search()`, `identify()`) and says what it knows (tags, status);
 * - the access addon says which access she holds;
 * - the automations addon says which automations she has been through.
 *
 * **Read only, and nothing is kept.** A source reads what it owns and returns it; the caller copies it into
 * an in-memory test person and forgets the rest. The person's name or address never goes to a log, and the
 * rights of the signed-in Control Panel user decide each source on its own: what she may not read from one
 * addon is left out of the copy, the rest still comes.
 */
interface ProvidesSimPersons
{
    /** A short stable name, e.g. `leadhub`. */
    public function personSourceId(): string;

    /** Whether this user may read persons from here at all. */
    public function personsAuthorized($user): bool;

    /**
     * People matching a search, for a picker. Only a source that finds people returns anything.
     *
     * @return list<array{key: string, label: string}> the key is opaque to the caller; the label is what a human recognises her by
     */
    public function searchPersons(string $query, int $limit): array;

    /**
     * Who a key from {@see searchPersons()} is: the identity the other sources look her up by. Null when the key
     * is not this source's.
     *
     * @return array{email?: string, id?: string|int}|null
     */
    public function identifyPerson(string $key): ?array;

    /**
     * What this source knows about the person with this identity.
     *
     * - `traits`: values a condition can read, by dotted key (`contact.tags` is a list, `contact.status` a string);
     * - `access`: slugs of the access she holds now;
     * - `ran`: handles of the automations she has been through;
     * - `unsubscribed`: she asked not to be written to.
     *
     * @param  array{email?: string, id?: string|int}  $identity
     * @return array{traits?: array<string, mixed>, access?: list<string>, ran?: list<string>, unsubscribed?: bool}
     */
    public function personFacet(array $identity): array;
}
