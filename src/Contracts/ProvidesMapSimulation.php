<?php

namespace Goldnead\BrandContext\Contracts;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;

/**
 * Optional third half of {@see ProvidesMapObjects}: what the object would do in a dry run.
 *
 * "Person X, moment T, event E: what do you do, and what does that set off next?" The answer is a
 * list of effects. Nothing is executed: no mail, no job, no event, no write, not even a run row.
 * The clock is the `$at` argument, never `now()`. The provider reuses only the pure parts of its
 * own logic (a condition evaluator, a trigger match, a wait calculation, a mail renderer) and
 * keeps the logic where the data is.
 *
 * With {@see SimPerson::unknown()} the provider forks at every condition it cannot decide; with a
 * known person it returns the one path she would take. A step it does not understand is an
 * {@see Effect::UNKNOWN} effect, not a guess.
 *
 * Duck typed like the other optional halves: the registry finds `react()` with `method_exists`, so
 * a provider does not have to implement this interface. If it has `react()` it must also have
 * `simulationAuthorized()`, the right that guards the object in its own Control Panel, otherwise
 * the registry refuses (a dry run shows subjects and bodies of mails).
 */
interface ProvidesMapSimulation
{
    /**
     * @return list<Effect> the first effects; later ones hang below as children
     */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array;

    /** Whether this user may see the dry run of this object. */
    public function simulationAuthorized(string $id, $user): bool;
}
