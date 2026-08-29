<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Laravel\Facades\Vpos as VposFacade;
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

it('leaves the client to discover its own PSR-18 implementation when nothing is bound', function (): void {
    expect(app()->bound(ClientInterface::class))->toBeFalse()
        ->and(ClientInternals::httpClientOf(app(Vpos::class)))->toBeInstanceOf(RefusingHttpClient::class);
});
