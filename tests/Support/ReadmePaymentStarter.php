<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;
use DavitVardanyan\AmeriabankVpos\Vpos;

/**
 * The README's controller example, with the send taken off the end.
 *
 * The README documents constructor injection as the supported route and shows
 * `backUrl: $this->backUrl->resolve()` inside an `InitPaymentRequest`. Prose in
 * a README is a claim like any other, and the claim here is a **call path**:
 * that the container can build these three dependencies together, that the
 * resolver is one of the things it can build, and that what `resolve()` returns
 * is accepted by the core's request model as its `BackURL`.
 *
 * It carries the same three constructor arguments in the same order as the
 * published example, so the container work being asserted is the work the
 * example asks the container to do — a resolver resolved on its own through
 * `app(BackUrlResolver::class)` would not have shown that a type hint beside
 * two client dependencies is satisfiable.
 *
 * **`init()` is deliberately not called.** `InitPayment` registers a real order
 * with the gateway, which is why `vpos:check` may not use it either; the
 * request model is built and handed back instead, and its serialised `BackURL`
 * is the observable end of the path. Nothing here reaches the network, and
 * `PaymentsClient` is present because the example takes it, not because it is
 * used.
 *
 * The amount, order number and description are the README's own literals. None
 * of them is a credential.
 */
final readonly class ReadmePaymentStarter
{
    public function __construct(
        private PaymentsClient $payments,
        private Vpos $vpos,
        private BackUrlResolver $backUrl,
    ) {}

    /**
     * The request the README's `$init` line builds, up to but not including the
     * send.
     */
    public function request(): InitPaymentRequest
    {
        return new InitPaymentRequest(
            amount: Amount::fromMinorUnits(1000, Currency::AMD),
            orderId: 1749,
            backUrl: $this->backUrl->resolve(),
            description: 'Order 1749',
            opaque: 'basket-1749',
            timeout: 900,
        );
    }

    /**
     * The two dependencies the example takes and this double does not send
     * through, returned so that a test can assert the container really built
     * them rather than leaving them for a lazier failure later.
     *
     * @return array{PaymentsClient, Vpos}
     */
    public function collaborators(): array
    {
        return [$this->payments, $this->vpos];
    }
}
