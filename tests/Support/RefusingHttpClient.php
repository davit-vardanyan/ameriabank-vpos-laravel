<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function sprintf;

/**
 * The PSR-18 client this suite falls back to, which never sends anything.
 *
 * No test may reach the network. Two routes could take one there: a test that
 * forgets to bind a client, and a mutant that turns the provider's
 * "use the bound client" branch into "let discovery choose" — and discovery in
 * this vendor tree finds a real Guzzle client, which would send the probe to
 * the bank. RefusingClientStrategy puts this class in front of both, so the
 * absence of a stub is an exception naming the request rather than a request.
 *
 * The refusal deliberately does **not** implement ClientExceptionInterface. The
 * transport catches that interface and translates it into a TransportException,
 * which the check command reports as "could not reach the gateway" — a green,
 * plausible line that would hide the fact that a test had tried to leave the
 * machine. An ordinary RuntimeException passes through every catch clause in
 * the client and fails the test where the attempt was made.
 */
final class RefusingHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new RuntimeException(sprintf(
            'A test attempted a real HTTP request: %s %s. No test in this suite may reach the '
            .'network. Bind a %s into the container before the exchange.',
            $request->getMethod(),
            $request->getUri()->__toString(),
            StubHttpClient::class,
        ));
    }
}
