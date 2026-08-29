<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

use function sprintf;

/**
 * Reads the two things the provider decides and the client does not expose.
 *
 * Which PSR-18 client and which logger a Vpos was built with are exactly the
 * provider's decisions — config-driven, and wrong in ways nothing else would
 * notice — but Vpos deliberately exposes neither: the transport is a private
 * of the operation clients, and it keeps the logger to itself.
 *
 * The alternative assertions are all weaker in the direction that matters. A
 * "nothing was logged" check cannot tell a NullLogger from a channel nobody
 * wrote to, and a "the request went to the stub" check says nothing about
 * which logger the same constructor was handed.
 *
 * It is deliberately brittle. Every hop is checked and every failure names the
 * hop, so a change to the client's shape stops this suite with a sentence
 * rather than quietly reporting that the provider's wiring is fine.
 */
final class ClientInternals
{
    /**
     * The PSR-18 client the transport will actually send through.
     */
    public static function httpClientOf(Vpos $vpos): ClientInterface
    {
        $client = self::read(self::transportOf($vpos), HttpTransport::class, 'client');

        if (! $client instanceof ClientInterface) {
            throw new RuntimeException('The transport is holding something that is not a PSR-18 client.');
        }

        return $client;
    }

    /**
     * The logger the transport writes its exchange records to.
     */
    public static function loggerOf(Vpos $vpos): LoggerInterface
    {
        $logger = self::read(self::transportOf($vpos), HttpTransport::class, 'logger');

        if (! $logger instanceof LoggerInterface) {
            throw new RuntimeException('The transport is holding something that is not a PSR-3 logger.');
        }

        return $logger;
    }

    /**
     * The one transport all three operation clients share.
     */
    private static function transportOf(Vpos $vpos): HttpTransport
    {
        $transport = self::read($vpos->payments(), PaymentsClient::class, 'transport');

        if (! $transport instanceof HttpTransport) {
            throw new RuntimeException('The payments client is holding something that is not the transport.');
        }

        return $transport;
    }

    /**
     * @param  class-string  $class
     */
    private static function read(object $subject, string $class, string $property): mixed
    {
        $reflection = new ReflectionProperty($class, $property);

        if (! $reflection->isInitialized($subject)) {
            throw new RuntimeException(sprintf('%s::$%s has not been initialised.', $class, $property));
        }

        return $reflection->getValue($subject);
    }
}
