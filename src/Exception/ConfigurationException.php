<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Exception;

use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use LogicException;
use Throwable;

use function array_map;
use function implode;
use function is_int;
use function is_string;
use function sprintf;

/**
 * The Laravel side of the client was configured incorrectly.
 *
 * A sibling of the core client's ConfigurationException rather than a subclass:
 * that class is final, so extending it is not available, and the two describe
 * different mistakes anyway. The core's covers a client assembled wrongly in
 * PHP — a missing PSR-18 implementation, a blank credential. This one covers a
 * Laravel application configured wrongly: an environment name the client does
 * not know, a `back_url` naming a route that does not exist, a callback asked
 * for outside the request that would carry it.
 *
 * It implements the core's VposExceptionInterface all the same, so one
 * `catch (VposExceptionInterface $e)` around a payment flow still catches
 * everything either package can raise. It extends LogicException for the same
 * reason the core's does: every case below is a programming or deployment
 * mistake that is present before the first request, not a runtime condition
 * that a retry could clear.
 *
 * The constructor is private. Every message this class can emit is written in
 * exactly one named factory, so the set of things it can say is enumerable by
 * reading the class, and no call site can invent a variant.
 */
final class ConfigurationException extends LogicException implements VposExceptionInterface
{
    /**
     * Null until this object has been through a serialization round trip.
     *
     * @see VposExceptionInterface::chainDropped()
     */
    private ?bool $chainDropped = null;

    /**
     * Private, so the named factories below are the only way in.
     *
     * The code is always 0, matching every exception the core package throws,
     * so nothing downstream has to decide whether this package's codes mean
     * anything. They do not.
     */
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The configured environment is not one the client knows.
     *
     * Names the offending value, and lists the accepted ones by reading the
     * enum rather than by repeating them here — a hand-kept list would be a
     * second place to update the day a third environment appears.
     *
     * @param  string  $value  The configured value, verbatim. Not a credential.
     */
    public static function unknownEnvironment(string $value): self
    {
        return new self(sprintf(
            'Unknown Ameriabank vPOS environment "%s". Set ameriabank-vpos.environment '
            .'(AMERIABANK_VPOS_ENVIRONMENT) to one of: %s.',
            $value,
            implode(', ', array_map(
                static fn (Environment $case): string => $case->value,
                Environment::cases(),
            )),
        ));
    }

    /**
     * `back_url` is empty, missing, whitespace-only, or not a string at all.
     *
     * Refused rather than defaulted. An empty BackURL is accepted by the
     * gateway and sends the customer nowhere after paying, which is discovered
     * by a customer rather than by a deployment.
     */
    public static function blankBackUrl(): self
    {
        return new self(
            'ameriabank-vpos.back_url (AMERIABANK_VPOS_BACK_URL) is blank. Set it to an '
            .'absolute http or https URL, or to the name of a route in your application.',
        );
    }

    /**
     * `back_url` is not an absolute URL, and is not a route this application
     * has registered either.
     *
     * Names the value, because the whole difficulty of this mistake is that a
     * route name and a typo look identical.
     *
     * @param  string  $value  The configured value, verbatim. Not a credential.
     */
    public static function unresolvableBackUrlRoute(string $value, Throwable $previous): self
    {
        return new self(
            sprintf(
                'ameriabank-vpos.back_url is "%s", which is neither an absolute http or https URL '
                .'nor the name of a registered route. Name a route, or configure a full URL.',
                $value,
            ),
            $previous,
        );
    }

    /**
     * `back_url` names a route this application has registered, but that route
     * declares required parameters.
     *
     * A separate factory from the one above, and deliberately so. The message
     * above would be false here — the value *is* the name of a registered
     * route — and it would send the reader hunting for a typo in a spelling
     * that is correct. What is wrong is the route that was chosen, not the
     * name that was typed, and the two mistakes have different remedies.
     *
     * Neither the route's URI pattern nor the parameter it wanted is repeated
     * here. This class composes its own sentences out of what it was handed,
     * and the only source for either of those is the framework's message,
     * which would have to be parsed back apart — a string another package is
     * free to reword. The cause carries both intact, which is why it is
     * chained, and the message says so rather than leaving it to be guessed.
     *
     * @param  string  $value  The configured value, verbatim. Not a credential.
     */
    public static function parameterisedBackUrlRoute(string $value, Throwable $previous): self
    {
        return new self(
            sprintf(
                'ameriabank-vpos.back_url is "%s", which names a route this application has registered, but '
                .'that route declares required parameters and this package has none to give it. The BackURL is '
                .'the address the gateway returns the customer to, so it has to resolve on its own: point '
                .'ameriabank-vpos.back_url (AMERIABANK_VPOS_BACK_URL) at a route that takes no required '
                .'parameters, or at an absolute http or https URL. The cause names the route and what it wanted.',
                $value,
            ),
            $previous,
        );
    }

    /**
     * A VposCallback was resolved where no vPOS callback exists.
     *
     * The core's message names the field that was missing, which is the right
     * answer when a callback really did arrive and was malformed. It is the
     * wrong answer — and a confusing one — when the resolution happened in a
     * console command, a queued job, or an ordinary page that the gateway never
     * redirected to. So the core's message is kept as the cause and this one
     * supplies the context around it.
     */
    public static function callbackOutsideRequest(Throwable $previous): self
    {
        return new self(
            'VposCallback can only be resolved during a request carrying Ameriabank vPOS '
            .'callback parameters, and this request carries none that can be read. Resolve it '
            .'in the controller handling your back_url, or build one explicitly with '
            .'VposCallback::fromQuery(). The cause names the parameter that was missing.',
            $previous,
        );
    }

    public function chainDropped(): ?bool
    {
        return $this->chainDropped;
    }

    /**
     * Replaces the default serialized state wholesale.
     *
     * A default-serialized exception carries its stack trace, and a trace
     * carries the arguments of every live frame. The frames above this class
     * are container closures holding the application itself, so the default
     * state is not reliably serializable at all — and where it is, it publishes
     * objects this package does not own. Naming the state explicitly means only
     * what is listed here can ever leave the process.
     *
     * `previous` is dropped for the same reason the core drops it, and that it
     * was dropped is recorded rather than silently lost.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'chainDropped' => $this->getPrevious() instanceof Throwable,
        ];
    }

    /**
     * Writes the captured state back over the restore site's own.
     *
     * `unserialize()` never runs a constructor, so `file` and `line` arrive
     * describing the frame that called `unserialize()`. Overwriting them is
     * what makes a restored object still point at where the misconfiguration
     * was found.
     *
     * Nothing here throws. A payload with a missing or wrong-typed key yields a
     * degraded object rather than a TypeError inside `unserialize()`, which
     * would turn a configuration mistake into a fatal in whichever worker
     * happened to read it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $message = $data['message'] ?? null;
        $file = $data['file'] ?? null;
        $line = $data['line'] ?? null;

        $this->message = is_string($message) ? $message : '';
        $this->file = is_string($file) ? $file : '';
        $this->line = is_int($line) ? $line : 0;
        $this->chainDropped = ($data['chainDropped'] ?? null) === true;
    }
}
