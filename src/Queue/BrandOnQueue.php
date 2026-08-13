<?php

namespace Goldnead\BrandContext\Queue;

use Goldnead\BrandContext\BrandManager;
use Goldnead\BrandContext\Models\Brand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The brand travels with the job.
 *
 * A queue worker has no request behind it, so nothing sets a current brand —
 * and under multi-brand that is not "unscoped", it is fail-closed: every
 * branded query in the job returns zero rows. The job then does nothing at all,
 * successfully. No exception, no failed_jobs row, no log line. A campaign
 * fan-out dispatched from a request stayed in `sending` for ever, because the
 * job that was supposed to snapshot its audience could not even see the
 * campaign it was handed the handle of. Measured on a live install, 13.08.2026,
 * before this class existed.
 *
 * So the brand that was current when the job was PUSHED is written into the
 * payload, and set again while the job runs. Two properties make that safe:
 *
 *  - Nothing is invented. A job pushed with no current brand carries no key and
 *    runs exactly as before — fail-closed. `currentId()` is deliberately not
 *    used, because it answers with the default brand when nothing is set, and a
 *    job silently widening from "no brand" to "the default brand" is the one
 *    outcome worse than doing nothing.
 *  - The previous value is restored, not forgotten. On the `sync` connection a
 *    job runs inside the request that dispatched it; clearing the brand
 *    afterwards would take it away from the rest of that request. That is why
 *    this keeps a stack rather than calling `forget()`.
 *
 * A job whose brand no longer exists (deleted between push and run) runs with
 * no brand and says so once. Guessing would be worse: fail-closed is the same
 * answer the system gives everywhere else when it cannot tell whose data it is
 * looking at.
 */
class BrandOnQueue
{
    /**
     * The payload key. Prefixed with the package name because a queue payload
     * is shared by every package in the application, and `brand_id` is a word
     * more than one of them could reach for.
     */
    public const PAYLOAD_KEY = 'brandContextBrandId';

    /**
     * The brands to put back, innermost last.
     *
     * A stack rather than a single slot: on `sync`, a job dispatched from
     * inside another job runs nested, and the inner one must not restore the
     * outer one's brand on its way out.
     *
     * @var list<Brand|null>
     */
    protected array $previous = [];

    /**
     * The manager of the CURRENT container, never one captured at boot.
     *
     * Nothing here holds a reference to a container: an object that outlives a
     * boot — and the static payload hook does — would otherwise answer for an
     * application that no longer exists.
     */
    protected function brands(): BrandManager
    {
        return app('brand-context');
    }

    /**
     * Wire the payload writer and the events that bracket a job.
     *
     * The multi-brand check is deliberately NOT made here. It is made in each
     * callback, at call time: a host that switches the flag — or a test that
     * does — would otherwise keep whatever the first boot decided, in one
     * direction silently doing nothing and in the other stamping a payload key
     * on a single-brand install for ever.
     */
    public function register(Dispatcher $events): void
    {
        // On the base class, not through the `Queue` facade. The facade points
        // at the QueueManager, which forwards an unknown method to the DEFAULT
        // connection — so a call that only registers a callback would open a
        // Redis or database connection at boot, in every process, including
        // those that never queue anything.
        //
        // `Queue::createPayloadUsing()` collects into a static array, so a
        // process that boots the application twice — Octane, and every test
        // that builds a fresh one — registers this twice, and every payload
        // built afterwards calls all of them. That is harmless HERE and only
        // here: the callback closes over nothing, asks the current container
        // for the manager, and therefore answers the same whichever boot
        // registered it. The cost is a few identical calls per push, and it is
        // bounded by the number of boots, which outside a test suite is one.
        //
        // A version that captured the manager at registration would have to be
        // registered exactly once, and could not be: Laravel empties that array
        // between tests, so a one-shot guard left every test after the first
        // with no hook at all — measured, on this suite, before this comment
        // was written.
        BaseQueue::createPayloadUsing(fn (): array => app(static::class)->payload());

        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $this->enter($event));

        // Processed and ExceptionOccurred are the two mutually exclusive ends
        // of a job: a job that threw never raises Processed, and a job that
        // returned never raises ExceptionOccurred. JobFailed is deliberately
        // NOT listened to — it accompanies one of the two rather than replacing
        // it, so restoring on it as well would pop the stack twice for one job
        // and hand the wrong brand back to whoever comes next.
        $events->listen(JobProcessed::class, fn () => $this->leave());
        $events->listen(JobExceptionOccurred::class, fn () => $this->leave());

        // Between two jobs in a long-lived worker nothing is in flight, so
        // anything still on the stack is leftover from a job that ended in a
        // way the two events above did not describe. Dropping it here keeps a
        // worker that has run for days from serving job number 40,000 the brand
        // of job number 12.
        $events->listen(Looping::class, function (): void {
            $brands = $this->brands();

            if (! $brands->multiBrandEnabled()) {
                return;
            }

            $this->previous = [];
            $brands->forget();
        });
    }

    /** @return array<string, int> */
    public function payload(): array
    {
        $brands = $this->brands();

        if (! $brands->multiBrandEnabled() || ! $brands->hasCurrent()) {
            return [];
        }

        return [self::PAYLOAD_KEY => $brands->currentId()];
    }

    protected function enter(JobProcessing $event): void
    {
        $brands = $this->brands();

        if (! $brands->multiBrandEnabled()) {
            return;
        }

        $this->previous[] = $brands->hasCurrent() ? $brands->current() : null;

        $brandId = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if (! is_int($brandId)) {
            $brands->forget();

            return;
        }

        try {
            $brands->setCurrent($brandId);
        } catch (Throwable $e) {
            $brands->forget();

            Log::warning(
                "brand-context: queued job [{$event->job->resolveName()}] carries brand [{$brandId}], "
                .'which no longer exists. The job runs with no brand, which under fail-closed means '
                .'it will see no branded data at all. Reason: '.$e->getMessage()
            );
        }
    }

    protected function leave(): void
    {
        if ($this->previous === []) {
            return;
        }

        $this->brands()->setCurrent(array_pop($this->previous));
    }
}
