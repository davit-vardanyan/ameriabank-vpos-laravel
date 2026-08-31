<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Support;

use const PHP_URL_SCHEME;

use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Exceptions\UrlGenerationException;
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
 * That is the whole point of the three refusals below. The gateway accepts an
 * empty BackURL and then sends the paying customer nowhere, which is a fault
 * discovered by a customer rather than by a deployment. A blank value, a route
 * name this application has not registered, and a route name that is
 * registered but cannot be built without parameters all throw, and the two
 * that name a route name print it, because a route name and a typo of one are
 * indistinguishable to look at.
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
     * **Two route-name mistakes are converted, and they are converted
     * separately.** An unregistered route name arrives as an
     * `InvalidArgumentException` — Symfony's `RouteNotFoundException`. A route
     * name that *is* registered but declares required parameters arrives as
     * `UrlGenerationException`, which extends `Exception` directly and so is
     * not an `InvalidArgumentException` at all; catching one has never caught
     * the other, and widening a single clause could not have. They therefore
     * take two clauses and two factories.
     *
     * They are not merged, because the messages are not interchangeable.
     * Reporting a parameterised route as "neither an absolute URL nor the name
     * of a registered route" would be false — it is the name of a registered
     * route — and would send the reader looking for a typo that is not there.
     * `back_url` is handed to the gateway as a plain redirect target with no
     * parameters this package can supply, so that case is reported as what it
     * is: the wrong route rather than a misspelt one.
     *
     * @throws ConfigurationException when the value is blank, names a route this application has not registered, or names one that cannot be built without parameters
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
            // Static analysis reads this clause as dead, and it is wrong for a reason worth recording:
            // Illuminate\Contracts\Routing\UrlGenerator::route() documents `@throws \InvalidArgumentException`
            // and nothing else, while the generator behind that contract raises UrlGenerationException. The
            // suppression is not a standing exemption — an unmatched ignore is itself a non-ignorable error,
            // so the day the contract declares this exception, this line fails and has to be deleted. The
            // package's own suite holds the other half, catching this exception as the chained cause below.
            // @phpstan-ignore catch.neverThrown
        } catch (UrlGenerationException $failure) {
            throw ConfigurationException::parameterisedBackUrlRoute($value, $failure);
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
