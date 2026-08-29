<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The message the provider wraps the client's refusal in, in full.
 */
const CALLBACK_REFUSAL = 'VposCallback can only be resolved during a request carrying Ameriabank vPOS '
    .'callback parameters, and this request carries none that can be read. Resolve it in the controller '
    .'handling your back_url, or build one explicitly with VposCallback::fromQuery(). The cause names the '
    .'parameter that was missing.';

/**
 * A payment identifier in the case the gateway sends it in — lower.
 *
 * Not a real identifier and not a claim about the format: the client records
 * that InitPayment answers with an upper-case GUID and that the callback echoes
 * the identical value entirely in lower case, and it is that echo this suite
 * has to prove survives the trip through the container unchanged.
 */
const CALLBACK_PAYMENT_ID = 'a1b2c3d4-e5f6-4a2b-8c3d-0f1e2d3c4b5a';

/**
 * Sends the callback through the application's own HTTP kernel.
 *
 * The kernel rather than a test helper, so the global middleware stack runs:
 * what the gateway put on the wire is not what a controller receives, and this
 * suite has to be able to see the difference.
 */
function callbackRequest(): Response
{
    return callbackRequestFor(gatewayCallbackQuery());
}

/**
 * The same round trip, for a query this suite has altered on purpose.
 *
 * @param  array<string, string>  $query
 */
function callbackRequestFor(array $query): Response
{
    return app(Kernel::class)->handle(
        Request::create('/checkout/vpos/back?'.http_build_query($query)),
    );
}

/**
 * The JSON body the callback route answered with.
 */
function callbackPayload(Response $response): mixed
{
    return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The five parameters a successful callback arrives with, in the gateway's own
 * spellings — `resposneCode` typo and trailing space included.
 *
 * @return array<string, string>
 */
function gatewayCallbackQuery(): array
{
    return [
        'paymentID' => CALLBACK_PAYMENT_ID,
        'orderID' => '1749',
        'opaque' => 'basket-1749',
        'resposneCode' => '00',
        'description' => 'Operation Approved ',
    ];
}

/**
 * The same callback with an empty diagnostic: `description=` on the wire,
 * present and blank rather than missing.
 *
 * @return array<string, string>
 */
function callbackQueryWithEmptyDescription(): array
{
    return array_merge(gatewayCallbackQuery(), ['description' => '']);
}

/**
 * The same callback with no diagnostic description at all.
 *
 * @return array<string, string>
 */
function callbackQueryWithoutDescription(): array
{
    $query = gatewayCallbackQuery();

    unset($query['description']);

    return $query;
}

/**
 * A merchant's back_url controller, taking the callback as a parameter.
 *
 * A closure route registered by a test, which is the point being proved: the
 * binding has to work where a controller would take it.
 *
 * @return Closure(VposCallback): array<string, mixed>
 */
function callbackRoute(): Closure
{
    return static fn (VposCallback $callback): array => [
        'paymentId' => $callback->paymentId(),
        'orderId' => $callback->orderId(),
        'opaque' => $callback->opaque(),
        'diagnostics' => $callback->untrustedDiagnostics(),
    ];
}

beforeEach(function (): void {
    vposConfig();
});

it('resolves from the request the gateway redirected to', function (): void {
    Route::get('/checkout/vpos/back', callbackRoute());

    $response = callbackRequest();

    expect($response->getStatusCode())->toBe(200)
        ->and(callbackPayload($response))->toBe([
            'paymentId' => CALLBACK_PAYMENT_ID,
            'orderId' => '1749',
            'opaque' => 'basket-1749',
            'diagnostics' => [
                'resposneCode' => '00',
                'description' => 'Operation Approved',
            ],
        ]);
});

/*
 * The description above arrives with its trailing space removed, and that is
 * Laravel doing it rather than this package: TrimStrings is a global middleware
 * in the default HTTP kernel, so by the time any binding sees the query it has
 * already been rewritten.
 *
 * It matters here because the client documents `Operation Approved ` — trailing
 * space included, byte-identical across the two successful callbacks on
 * record — as a value it passes through untouched, and a merchant comparing
 * that string in an application with the default middleware stack will not find
 * it. The next test pins the other half, using the framework's own opt-out:
 * with the trimming skipped, what the gateway sent is exactly what the
 * controller gets.
 */
it('passes the gateway diagnostics through verbatim when the application does not trim them', function (): void {
    Route::get('/checkout/vpos/back', callbackRoute());

    TrimStrings::skipWhen(static fn (): bool => true);

    try {
        $response = callbackRequest();

        expect($response->getStatusCode())->toBe(200)
            ->and(callbackPayload($response))->toBe([
                'paymentId' => CALLBACK_PAYMENT_ID,
                'orderId' => '1749',
                'opaque' => 'basket-1749',
                'diagnostics' => [
                    'resposneCode' => '00',
                    'description' => 'Operation Approved ',
                ],
            ]);
    } finally {
        TrimStrings::flushState();
    }
});

/*
 * The second default global middleware does something worse than rewrite the
 * gateway's text: ConvertEmptyStringsToNull deletes a distinction the client
 * keeps on purpose.
 *
 * `description=` and no `description` at all are two different things on the
 * wire, and VposCallback::readOptional() is written to keep them that way — an
 * empty string is returned verbatim, an absent parameter becomes null, and the
 * client's own docblock records that collapsing the two "would discard the only
 * signal available about which happened". ConvertEmptyStringsToNull performs
 * exactly that collapse, in the global stack, before any binding sees the
 * query. So a merchant on a default Laravel application receives null for both,
 * has no way to tell which arrived, and is never told the difference was ever
 * there.
 *
 * Both halves are pinned, as with the trimming above, because neither is
 * evidence alone. The collapse on its own would also be reported by an
 * application in which the parameter never arrived at all; the distinction on
 * its own would also be reported by one in which the middleware had been
 * removed for everybody. Only the pair separates "Laravel destroys this" from
 * "this was never here".
 */
it('collapses an empty description into an absent one under the default middleware', function (): void {
    Route::get('/checkout/vpos/back', callbackRoute());

    $empty = callbackRequestFor(callbackQueryWithEmptyDescription());
    $absent = callbackRequestFor(callbackQueryWithoutDescription());

    $collapsed = [
        'paymentId' => CALLBACK_PAYMENT_ID,
        'orderId' => '1749',
        'opaque' => 'basket-1749',
        'diagnostics' => [
            'resposneCode' => '00',
            'description' => null,
        ],
    ];

    expect($empty->getStatusCode())->toBe(200)
        ->and($absent->getStatusCode())->toBe(200)
        ->and(callbackPayload($empty))->toBe($collapsed)
        ->and(callbackPayload($absent))->toBe($collapsed);
});

it('keeps an empty description apart from an absent one when the application does not convert them', function (): void {
    Route::get('/checkout/vpos/back', callbackRoute());

    ConvertEmptyStringsToNull::skipWhen(static fn (): bool => true);

    try {
        $empty = callbackRequestFor(callbackQueryWithEmptyDescription());
        $absent = callbackRequestFor(callbackQueryWithoutDescription());

        expect($empty->getStatusCode())->toBe(200)
            ->and($absent->getStatusCode())->toBe(200)
            ->and(callbackPayload($empty))->toBe([
                'paymentId' => CALLBACK_PAYMENT_ID,
                'orderId' => '1749',
                'opaque' => 'basket-1749',
                'diagnostics' => [
                    'resposneCode' => '00',
                    'description' => '',
                ],
            ])
            ->and(callbackPayload($absent))->toBe([
                'paymentId' => CALLBACK_PAYMENT_ID,
                'orderId' => '1749',
                'opaque' => 'basket-1749',
                'diagnostics' => [
                    'resposneCode' => '00',
                    'description' => null,
                ],
            ]);
    } finally {
        ConvertEmptyStringsToNull::flushState();
    }
});

it('preserves the lower-case paymentID the gateway sends, byte for byte', function (): void {
    app()->instance('request', Request::create(
        '/checkout/vpos/back?paymentID='.CALLBACK_PAYMENT_ID.'&orderID=1749',
    ));

    $paymentId = app(VposCallback::class)->paymentId();

    expect($paymentId)->toBe(CALLBACK_PAYMENT_ID)
        ->and($paymentId)->not->toBe(strtoupper(CALLBACK_PAYMENT_ID))
        ->and(bin2hex($paymentId))->toBe(bin2hex(CALLBACK_PAYMENT_ID));
});

it('refuses to be resolved where no callback ever arrived, naming the context', function (): void {
    expect(fn (): VposCallback => app(VposCallback::class))
        ->toThrow(ConfigurationException::class, CALLBACK_REFUSAL);
});

it('keeps the client refusal that names the missing parameter as its cause', function (): void {
    try {
        app(VposCallback::class);
    } catch (ConfigurationException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(ValidationException::class)
            ->and($failure->getPrevious()?->getMessage())->toBe('Field "paymentID" must not be blank.');

        return;
    }

    throw new RuntimeException('A VposCallback was resolved from a request that carries no callback.');
});

it('refuses a request that carries only some of the callback parameters', function (): void {
    app()->instance('request', Request::create('/checkout/vpos/back?paymentID='.CALLBACK_PAYMENT_ID));

    expect(fn (): VposCallback => app(VposCallback::class))
        ->toThrow(ConfigurationException::class, CALLBACK_REFUSAL);
});
