<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function count;
use function sprintf;

/**
 * A PSR-18 client that answers exactly once, from a script.
 *
 * The seam the check command is tested through: the provider hands the
 * container's ClientInterface to the client it builds, so binding one of these
 * intercepts the only outbound call the command makes.
 *
 * It answers once and then refuses. A second call means the code under test
 * retried or looped, which is a finding rather than something to absorb
 * silently — the alternative, replaying the same canned answer forever, would
 * make a retry storm indistinguishable from a single exchange.
 *
 * The PSR-17 factories come from php-http/discovery, which the client package
 * requires, so no test-only HTTP implementation is assumed.
 */
final class StubHttpClient implements ClientInterface
{
    /**
     * @var list<RequestInterface>
     */
    private array $requests = [];

    private function __construct(
        private readonly ?int $status,
        private readonly ?string $body,
        private readonly ?ClientExceptionInterface $failure,
    ) {}

    /**
     * Answers with a status and a raw body, exactly as the gateway would.
     */
    public static function answering(int $status, string $body): self
    {
        return new self($status, $body, null);
    }

    /**
     * Fails the exchange the way a PSR-18 client reports a failure.
     */
    public static function failingWith(ClientExceptionInterface $failure): self
    {
        return new self(null, null, $failure);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if (count($this->requests) > 1) {
            throw new RuntimeException(sprintf(
                'The stub was asked for a second answer (%s %s). It scripts one exchange; a second '
                .'call means the code under test retried or looped.',
                $request->getMethod(),
                $request->getUri()->__toString(),
            ));
        }

        if ($this->failure instanceof ClientExceptionInterface) {
            throw $this->failure;
        }

        if ($this->status === null || $this->body === null) {
            throw new RuntimeException('The stub was scripted with neither an answer nor a failure.');
        }

        return Psr17FactoryDiscovery::findResponseFactory()
            ->createResponse($this->status)
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($this->body));
    }

    /**
     * Every request this stub was asked to send, in order.
     *
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
