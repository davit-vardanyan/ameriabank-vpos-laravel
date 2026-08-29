<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A PSR-18 client failure that is not a network failure.
 *
 * ClientExceptionInterface without NetworkExceptionInterface is what the
 * transport turns straight into a TransportException, with no retry loop and
 * no backoff sleep, which is the outcome the check command reports as
 * "could not reach the gateway".
 */
final class StubClientException extends RuntimeException implements ClientExceptionInterface {}
