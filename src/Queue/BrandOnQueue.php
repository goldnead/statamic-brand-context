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

    public function __construct(protected BrandManager $brands) {}

    /**
     * Wire the payload writer and the four events that bracket a job.
     *
     * Only under multi-brand. In single-brand mode there is exactly one brand,
     * the scope is a no-op, and a payload key describing it would be noise in
     * every queued job of every install that never turned multi-brand on.
     */
    public function register(Dispatcher $events): void
    {
        if (! $this->brands->multiBrandEnabled()) {
            return;
        }

        // On the base class, not through the `Queue` facade. The facade points
        // at the QueueManager, which forwards an unknown method to the DEFAULT
        // connection — so a call that only registers a callback would open a
        // Redis or database connection at boot, in every process, including
        // those that never queue anything.
        BaseQueue::createPayloadUsing(fn (): array => $this->payload());

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
            $this->previous = [];
            $this->brands->forget();
        });
    }

    /** @return array<string, int> */
    protected function payload(): array
    {
        if (! $this->brands->hasCurrent()) {
            return [];
        }

        return [self::PAYLOAD_KEY => $this->brands->currentId()];
    }

    protected function enter(JobProcessing $event): void
    {
        $this->previous[] = $this->brands->hasCurrent() ? $this->brands->current() : null;

        $brandId = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if (! is_int($brandId)) {
            $this->brands->forget();

            return;
        }

        try {
            $this->brands->setCurrent($brandId);
        } catch (Throwable $e) {
            $this->brands->forget();

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

        $this->brands->setCurrent(array_pop($this->previous));
    }
}
