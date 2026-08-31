<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Facades\Vpos as VposFacade;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ClientInternals;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\RefusingHttpClient;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubHttpClient;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface;

/**
 * Two callbacks the gateway could redirect to, in the case it sends them in.
 *
 * Payment identifiers, not credentials, and neither is real: they are shaped
 * like the GUIDs the gateway issues so the binding is exercised with what it
 * will actually receive, and they differ from the first character so a stale
 * one is unmistakable in a failure message.
 */
const FIRST_CALLBACK_PAYMENT_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

const SECOND_CALLBACK_PAYMENT_ID = 'bbbbbbbb-0000-4000-8000-000000000002';

beforeEach(function (): void {
    vposConfig();
});

it('resolves the client and its three operation clients', function (): void {
    expect(app(Vpos::class))->toBeInstanceOf(Vpos::class)
        ->and(app(PaymentsClient::class))->toBeInstanceOf(PaymentsClient::class)
        ->and(app(BindingsClient::class))->toBeInstanceOf(BindingsClient::class)
        ->and(app(ReportsClient::class))->toBeInstanceOf(ReportsClient::class);
});

it('hands out one client to the whole application', function (): void {
    /** @var Application $app */
    $app = app();

    expect($app->make(Vpos::class))->toBe($app->make(Vpos::class))
        ->and($app->isShared(Vpos::class))->toBeTrue();
});

it('caches none of the three operation clients in the container', function (): void {
    /** @var Application $app */
    $app = app();

    expect($app->isShared(PaymentsClient::class))->toBeFalse()
        ->and($app->isShared(BindingsClient::class))->toBeFalse()
        ->and($app->isShared(ReportsClient::class))->toBeFalse();

    $payments = $app->make(PaymentsClient::class);
    $bindings = $app->make(BindingsClient::class);
    $reports = $app->make(ReportsClient::class);

    $app->forgetInstance(Vpos::class);

    expect($app->make(PaymentsClient::class))->not->toBe($payments)
        ->and($app->make(BindingsClient::class))->not->toBe($bindings)
        ->and($app->make(ReportsClient::class))->not->toBe($reports);
});

/*
 * The fifth binding's lifetime, which is the one with a wrong answer that
 * looks right.
 *
 * VposCallback is built from the current request's query string. Shared, it
 * would be built once and then handed to every later resolution unchanged —
 * invisible under php-fpm, where the container dies with the request, and a
 * payment attributed to the wrong order under any long-lived worker.
 *
 * isShared() alone would be pinned by the container's bookkeeping rather than
 * by the consequence, so the second request is actually made: a shared binding
 * answers it with the first request's callback, and the identifiers say so.
 */
it('builds the callback from the request in hand rather than caching the first one', function (): void {
    /** @var Application $app */
    $app = app();

    $app->instance('request', Request::create(
        '/checkout/vpos/back?paymentID='.FIRST_CALLBACK_PAYMENT_ID.'&orderID=1749',
    ));

    $first = $app->make(VposCallback::class);

    $app->instance('request', Request::create(
        '/checkout/vpos/back?paymentID='.SECOND_CALLBACK_PAYMENT_ID.'&orderID=1750',
    ));

    $second = $app->make(VposCallback::class);

    expect($app->isShared(VposCallback::class))->toBeFalse(
        'VposCallback is bound as a shared instance. It is built from the current request, so sharing it hands '
        .'one request\'s callback to the next under any long-lived worker — a payment identifier attributed to '
        .'the wrong order. Bind it, do not make it a singleton.'
    )
        ->and($second)->not->toBe($first)
        ->and($first->paymentId())->toBe(FIRST_CALLBACK_PAYMENT_ID)
        ->and($first->orderId())->toBe('1749')
        ->and($second->paymentId())->toBe(SECOND_CALLBACK_PAYMENT_ID)
        ->and($second->orderId())->toBe('1750');
});

it('resolves each operation client from the client the container is holding', function (): void {
    $vpos = app(Vpos::class);

    expect(app(PaymentsClient::class))->toBe($vpos->payments())
        ->and(app(BindingsClient::class))->toBe($vpos->bindings())
        ->and(app(ReportsClient::class))->toBe($vpos->reports());
});

it('resolves the facade to the very same client the container holds', function (): void {
    expect(VposFacade::getFacadeRoot())->toBe(app(Vpos::class));
});

it('sends through the PSR-18 client the container holds', function (): void {
    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');

    app()->instance(ClientInterface::class, $stub);

    expect(ClientInternals::httpClientOf(app(Vpos::class)))->toBe($stub)
        ->and($stub->requests())->toBe([]);
});

/*
 * ---------------------------------------------------------------------------
 * The sixth binding, and the one assertion that can see it.
 *
 * `BackUrlResolver` takes two constructor arguments the container can resolve
 * on its own, so `app(BackUrlResolver::class)` answers whether or not the
 * provider binds it — which means **every resolution assertion in this suite is
 * blind to the binding's existence**. Deleting the whole `bind()` block by hand
 * left BackUrlTest and ServiceProviderTest green.
 *
 * `bound()` is what discriminates: it is false under autowiring and true only
 * with the binding present. It is asserted here so that removing the statement
 * is a red test rather than a silent no-op, and so that a constructor argument
 * the container cannot guess fails at registration instead of at a merchant's
 * call site.
 *
 * `bind`, not `singleton`, and the two resolutions are compared to show it. The
 * resolver reads `back_url` from whichever configuration repository the
 * container holds when `resolve()` is called; a cached instance would pin the
 * repository it was built with, so an application that replaces its
 * configuration — a worker reloading it, a test rebinding it — would keep
 * resolving a BackURL from a configuration no longer in force. That is the
 * divergence making this class public API exists to close, in a new shape.
 * ---------------------------------------------------------------------------
 */
it('binds the BackURL resolver rather than leaving it to autowiring', function (): void {
    /** @var Application $app */
    $app = app();

    expect($app->bound(BackUrlResolver::class))->toBeTrue(
        'BackUrlResolver is not bound in the container; it is only autowirable. Every resolution assertion in '
        .'this suite passes either way, so this is the one that sees the difference. It is public API and its '
        .'binding is a documented seam: bind it explicitly in the provider.'
    )
        ->and($app->make(BackUrlResolver::class))->toBeInstanceOf(BackUrlResolver::class)
        ->and($app->isShared(BackUrlResolver::class))->toBeFalse(
            'BackUrlResolver is bound as a shared instance. It reads back_url from whichever configuration '
            .'repository the container holds at the moment resolve() is called, so a cached one pins the '
            .'repository it was built with and goes on resolving a BackURL from a configuration no longer in '
            .'force. Bind it, do not make it a singleton.'
        )
        ->and($app->make(BackUrlResolver::class))->not->toBe($app->make(BackUrlResolver::class));
});

it('leaves the client to discover its own PSR-18 implementation when nothing is bound', function (): void {
    expect(app()->bound(AmeriabankVposServiceProvider::HTTP_CLIENT_KEY))->toBeFalse()
        ->and(app()->bound(ClientInterface::class))->toBeFalse()
        ->and(ClientInternals::httpClientOf(app(Vpos::class)))->toBeInstanceOf(RefusingHttpClient::class);
});

/*
 * ---------------------------------------------------------------------------
 * The PSR-18 seam, all three tiers.
 *
 * 1. the container key this package owns;
 * 2. Psr\Http\Client\ClientInterface, which is application-wide;
 * 3. nothing, and the core's discovery runs.
 *
 * Tier 2 is why tier 1 exists. `bound()` answers true for a binding, an
 * instance **or an alias**, so any package in the application can claim that
 * key — and a client bound there for some other API, carrying a base URI,
 * default headers, an auth middleware or a proxy, would be handed the vPOS
 * credential payload.
 *
 * The key is cited from the provider's own constant rather than retyped. A
 * literal here would let a typo in either place read as "nothing is bound",
 * which is the tier that fails silently by falling through.
 * ---------------------------------------------------------------------------
 */

it('sends through the client bound under the package-scoped key', function (): void {
    $scoped = StubHttpClient::answering(200, '{"ResponseCode":"00"}');

    app()->instance(AmeriabankVposServiceProvider::HTTP_CLIENT_KEY, $scoped);

    expect(ClientInternals::httpClientOf(app(Vpos::class)))->toBe($scoped)
        ->and($scoped->requests())->toBe([]);
});

/*
 * Tier 1 over tier 2, demonstrated by binding both.
 *
 * Either binding alone is asserted elsewhere in this file, and either test
 * would pass against a seam that read only the other key. This is the one that
 * settles the order, and the `not->toBe()` is the half that matters: a seam
 * still reading the application-wide binding first hands out $shared, and
 * $shared is the client the application went out of its way to say was not for
 * the bank.
 */
it('prefers the package-scoped client to the application-wide one', function (): void {
    $scoped = StubHttpClient::answering(200, '{"ResponseCode":"00"}');
    $shared = StubHttpClient::answering(200, '{"ResponseCode":"00"}');

    app()->instance(AmeriabankVposServiceProvider::HTTP_CLIENT_KEY, $scoped);
    app()->instance(ClientInterface::class, $shared);

    expect(ClientInternals::httpClientOf(app(Vpos::class)))->toBe($scoped)
        ->and(ClientInternals::httpClientOf(app(Vpos::class)))->not->toBe($shared)
        ->and($scoped->requests())->toBe([])
        ->and($shared->requests())->toBe([]);
});

/*
 * A tier-1 binding that is not a PSR-18 client is refused, not skipped.
 *
 * Falling through to tier 2 is the quiet answer and the wrong one: it would
 * send payment traffic through exactly the client the application had just
 * named a different one instead of. So the application-wide binding is present
 * here too, and the refusal happening anyway is what makes the assertion about
 * precedence rather than about a missing fallback.
 *
 * The message is asserted whole. It names the key and the type and nothing
 * else — nothing bound to a container key is assumed safe to print, because a
 * misconfigured factory or a string holding a token could be what arrived.
 */
it('refuses a package-scoped binding that is not a PSR-18 client, rather than falling back', function (): void {
    $shared = StubHttpClient::answering(200, '{"ResponseCode":"00"}');

    app()->instance(AmeriabankVposServiceProvider::HTTP_CLIENT_KEY, new stdClass);
    app()->instance(ClientInterface::class, $shared);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'The container key ameriabank-vpos.http-client is bound to a value of type stdClass, which does not '
        .'implement Psr\Http\Client\ClientInterface. That key is where this package looks for the PSR-18 '
        .'client to send Ameriabank vPOS traffic through, so it is refused rather than ignored: falling back to '
        .'the application-wide Psr\Http\Client\ClientInterface binding would send payment traffic through a '
        .'client this application asked it not to use. Bind a PSR-18 client there, or unbind the key and let '
        .'the application-wide binding or the client\'s own discovery choose.',
    );

    expect($shared->requests())->toBe([]);
});

/*
 * The key is the provider's own constant, and it is the string the README
 * tells an application to bind.
 *
 * Cited rather than retyped everywhere above, so this is the one place the
 * value itself is pinned — otherwise every assertion in this file would be
 * self-consistent with a constant that had silently changed, and an application
 * following the README would be binding a key nothing reads.
 */
it('publishes the package-scoped container key it documents', function (): void {
    expect(AmeriabankVposServiceProvider::HTTP_CLIENT_KEY)->toBe('ameriabank-vpos.http-client');
});
