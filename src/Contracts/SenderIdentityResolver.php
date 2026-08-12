<?php

namespace Goldnead\BrandContext\Contracts;

use Goldnead\BrandContext\Sending\BrandSenderIdentity;
use Goldnead\BrandContext\Sending\SenderIdentity;

/**
 * Answers "who does brand N send as, and over which mailer".
 *
 * The extension point exists so a host application can decide this its own way
 * without an addon having to know the host. The bundled implementation reads
 * `brands.settings.mail` (see {@see BrandSenderIdentity}); a host that keeps
 * sender identities somewhere else rebinds this interface in its own provider:
 *
 *     $this->app->bind(SenderIdentityResolver::class, MyResolver::class);
 *
 * An implementation must never throw. "I cannot answer" has two legitimate
 * shapes and neither of them is an exception:
 *
 * - {@see SenderIdentity::fromConfig()} — nothing is known about this brand, so
 *   nothing changes and the mail goes out exactly as it did before this
 *   interface existed. This is what a single-brand install always gets.
 * - {@see SenderIdentity::refusing()} — the brand *declared* a mail identity and
 *   it is not usable. Then no mail goes out at all. Falling back to the
 *   configured identity here would send this brand's mail from the address the
 *   global credentials belong to, which is the exact failure this contract
 *   exists to prevent, with the added charm of being silent.
 *
 * The difference between the two is "said nothing" versus "said something
 * broken", and it is the whole reason the refusal is a separate state rather
 * than a null.
 *
 * **Per-package sub-interfaces.** An addon that sends mail extends this
 * interface in its own namespace and binds its own default. That is not
 * ceremony: a host with several of these addons installed may want marketing
 * post resolved differently from transactional post, and one shared binding
 * cannot express that. Binding *this* interface changes the answer for every
 * addon that has not been rebound individually.
 */
interface SenderIdentityResolver
{
    /**
     * @param  int|null  $brandId  The brand the mail belongs to; null means
     *                             "the brand currently in context, if any".
     */
    public function resolve(?int $brandId): SenderIdentity;
}
