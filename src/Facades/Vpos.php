<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Facades;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Enum\Language;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Vpos as VposClient;
use Illuminate\Support\Facades\Facade;

/**
 * Static access to the one Vpos instance the container holds.
 *
 * The convenience, not the recommendation. Constructor injection of
 * PaymentsClient, BindingsClient or ReportsClient is the documented primary
 * route and the one that stays testable without the container; this facade
 * exists for the call sites where reaching for the container costs more than it
 * returns.
 *
 * It resolves the same singleton the container does, so a test that swaps the
 * binding swaps what this returns as well.
 *
 * The annotations below are not decoration. A facade is a magic-static call
 * site, and without them PHPStan sees `mixed` from the first arrow onwards and
 * every type error after it goes unreported — a blind spot exactly where the
 * money is. They are transcribed from the client's own signatures and must be
 * corrected whenever those change. That is checked rather than remembered:
 * `tests/Arch/FacadeContractTest.php` derives the client from
 * `getFacadeAccessor()` and compares the tags against its reflected methods in
 * both directions, so a tag with no method behind it and a method with no tag
 * each fail the suite. The guard records what it deliberately does not
 * compare; read it before adjusting a tag to make it pass.
 *
 * @method static PaymentsClient payments()
 * @method static BindingsClient bindings()
 * @method static ReportsClient reports()
 * @method static PaymentDetailsResponse verify(VposCallback $callback)
 * @method static string paymentPageUrl(string $paymentId, Language $language = Language::English, ?PaymentType $type = null)
 *
 * @see VposClient
 */
final class Vpos extends Facade
{
    /**
     * The container key this facade resolves.
     *
     * The client's own class name, so the facade and a constructor-injected
     * Vpos are the same object rather than two configurations of one.
     */
    protected static function getFacadeAccessor(): string
    {
        return VposClient::class;
    }
}
