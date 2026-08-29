<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Support;

use const PHP_URL_SCHEME;

use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use InvalidArgumentException;

use function is_string;
use function parse_url;
use function trim;

/**
 * Turns the configured `back_url` into the absolute URL the gateway is given.
 *
 * A merchant configures either a finished URL or the name of one of their own
 * routes, and a route name is by far the better answer: it survives a domain
 * change, a `/checkout` rename and a move behind a load balancer, and Laravel
 * already owns the machinery for it.
 *
 * ## It resolves when asked, never when registered
 *
 * Routes are not loaded while service providers register, so a resolver that
 * ran there would find no routes and would either fail every application or —
 * worse — quietly hand back nothing. This class is constructed by the container
 * and reads configuration inside resolve(), so the lookup happens at the moment
 * a request is being built and not before.
 *
 * ## It never returns an empty BackURL
 *
 * That is the whole point of the two refusals below. The gateway accepts an
 * empty BackURL and then sends the paying customer nowhere, which is a fault
 * discovered by a customer rather than by a deployment. A blank value and an
 * unresolvable route name both throw, and the second one names the value,
 * because a route name and a typo are indistinguishable to look at.
 *
 * @internal
 */
final readonly class BackUrlResolver
{
    /**
     * The configuration key this resolver reads, in full.
     */
    private const string CONFIG_KEY = 'ameriabank-vpos.back_url';

    public function __construct(
        private Repository $config,
        private UrlGenerator $url,
    ) {}

    /**
     * The absolute URL to hand the gateway as BackURL.
     *
     * An absolute `http` or `https` URL passes through untouched — not
     * trimmed, not normalised, not re-encoded. Anything else is taken to be a
     * route name and resolved through the application's URL generator.
     *
     * The scheme test is case-sensitive, and deliberately so. `HTTPS://…` is
     * read as a route name and produces a message naming the value, which says
     * more than silently accepting a spelling nothing else in the application
     * uses.
     *
     * @throws ConfigurationException when the value is blank, or names a route this application has not registered
     */
    public function resolve(): string
    {
        $configured = $this->config->get(self::CONFIG_KEY);
        $value = is_string($configured) ? $configured : '';

        if (trim($value) === '') {
            throw ConfigurationException::blankBackUrl();
        }

        if ($this->isAbsoluteHttpUrl($value)) {
            return $value;
        }

        try {
            return $this->url->route($value);
        } catch (InvalidArgumentException $failure) {
            throw ConfigurationException::unresolvableBackUrlRoute($value, $failure);
        }
    }

    /**
     * Whether the value carries an `http` or `https` scheme.
     *
     * parse_url() rather than a prefix comparison or FILTER_VALIDATE_URL: the
     * question asked is which scheme the value declares, and that is exactly
     * what parse_url() answers. A route name has no scheme, so the two cases
     * separate cleanly without either having to know about the other.
     */
    private function isAbsoluteHttpUrl(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }
}
