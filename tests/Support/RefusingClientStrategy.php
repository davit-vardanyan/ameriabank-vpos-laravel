<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

/**
 * Makes php-http/discovery hand out a client that cannot send.
 *
 * Prepended in tests/Pest.php. It answers only for PSR-18 clients and returns
 * nothing for every other type, so PSR-17 factory discovery keeps working
 * exactly as it does in production and only the one dangerous answer is
 * replaced.
 *
 * Without it, discovery in this vendor tree finds a real Guzzle client, and the
 * provider's "no client bound, let discovery choose" path — reached by a test
 * on purpose, and by a mutant by accident — would be one method call away from
 * the bank's gateway.
 */
final class RefusingClientStrategy implements DiscoveryStrategy
{
    /**
     * @param  string  $type
     * @return list<array{class: class-string, condition: bool}>
     */
    public static function getCandidates($type): array
    {
        if ($type !== ClientInterface::class) {
            return [];
        }

        return [['class' => RefusingHttpClient::class, 'condition' => true]];
    }
}
