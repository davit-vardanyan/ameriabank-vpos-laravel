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
 * machine.
 *
 * ## The refusal is also recorded, because throwing is no longer enough
 *
 * Raising an ordinary RuntimeException used to be sufficient: it passed through
 * every catch clause in the client package and failed the test where the attempt
 * was made. `CheckCommand::handle()` now ends in a `catch (Throwable)` clause,
 * which is right — an unclassified failure must not leave that command on exit 1,
 * the code that means the gateway refused the merchant's credentials — but it
 * catches this refusal along with everything else and converts it into exit 2 and
 * an "unexpected RuntimeException" line, which several tests assert on their own
 * account. A test that forgot to bind a stub would have gone green while trying
 * to talk to the bank.
 *
 * So the attempt is written down before it is thrown, where no catch clause can
 * reach it, and tests/Pest.php fails the test that made it. The exception is
 * still raised: it is what stops the request, and it is the better failure for
 * every caller that does not swallow it.
 */
final class RefusingHttpClient implements ClientInterface
{
    /**
     * Every request this suite tried to actually send, since the last reset.
     *
     * @var list<string>
     */
    private static array $attempts = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $attempt = sprintf('%s %s', $request->getMethod(), $request->getUri()->__toString());

        self::$attempts[] = $attempt;

        throw new RuntimeException(sprintf(
            'A test attempted a real HTTP request: %s. No test in this suite may reach the '
            .'network. Bind a %s into the container before the exchange.',
            $attempt,
            StubHttpClient::class,
        ));
    }

    /**
     * The requests this suite tried to send, in order.
     *
     * @return list<string>
     */
    public static function attempts(): array
    {
        return self::$attempts;
    }

    /**
     * Empties the log, so one test's attempt cannot fail the next one.
     */
    public static function forgetAttempts(): void
    {
        self::$attempts = [];
    }
}
