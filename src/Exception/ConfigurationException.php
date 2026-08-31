<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Exception;

use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use Exception;
use LogicException;
use ReflectionProperty;
use Throwable;

use function array_flip;
use function array_intersect_key;
use function array_map;
use function implode;
use function is_array;
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
     * A default-serialized exception carries its stack trace, and a frame of
     * that trace carries the arguments its function was called with. The frames
     * above this class are container closures, called with the application
     * itself and holding a Closure of their own, so the default state publishes
     * objects this package does not own and is frequently not serializable at
     * all. Naming the state explicitly means only what is listed here can ever
     * leave the process.
     *
     * The trace is listed, and does leave the process — narrowed by
     * `safeFrames()` to `file`, `line`, `function`, `class` and `type`. Those
     * five name code, not values: they say where the mistake was found and by
     * what call, and none of them can carry a card number, a credential or an
     * application object. `args`, which can carry all three, is dropped — and
     * dropping it is exactly what makes a closure-holding trace serializable, so
     * publishing the trace costs nothing that was previously being protected.
     *
     * The trace is published because omitting it does not withhold it. A
     * restored exception gets a trace either way: `unserialize()` runs no
     * constructor, so with no trace in the payload the engine leaves the one it
     * built at the *restore* site, arguments and all. Sending the narrowed trace
     * is what lets `__unserialize()` overwrite that, so the omission was the
     * leak and the entry is the fix.
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
            'trace' => $this->safeFrames($this->getTrace()),
            'chainDropped' => $this->getPrevious() instanceof Throwable,
        ];
    }

    /**
     * Writes the captured state back over the restore site's own.
     *
     * `unserialize()` never runs a constructor, so `file`, `line` and `trace`
     * arrive describing the frame that called `unserialize()`. Overwriting them
     * is what makes a restored object still point at where the misconfiguration
     * was found — and, for the trace, what stops it describing the worker that
     * read the payload, whose own frames carry whatever arguments that worker
     * was called with.
     *
     * `trace` belongs to the engine rather than to this class, so it is reached
     * by reflection over Exception's own property. There is no other way in:
     * the property is private to a class this one only inherits from.
     *
     * The frames are narrowed again on the way in, by the type of each value as
     * well as by its name. A payload is something that arrived from outside this
     * process, so what it claims to be a trace is not trusted to already be one
     * — the outbound filter protects a consumer of this package, and this one
     * protects the process doing the restoring.
     *
     * Nothing here throws. A payload with a missing or wrong-typed key yields a
     * degraded object — an empty trace, or a frame short a key, among the rest —
     * rather than a TypeError inside `unserialize()`, which would turn a
     * configuration mistake into a fatal in whichever worker happened to read
     * it. Nor does it defer one: every key that survives holds the type
     * `getTraceAsString()` expects to read it back as.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $message = $data['message'] ?? null;
        $file = $data['file'] ?? null;
        $line = $data['line'] ?? null;
        $trace = $data['trace'] ?? null;

        $this->message = is_string($message) ? $message : '';
        $this->file = is_string($file) ? $file : '';
        $this->line = is_int($line) ? $line : 0;
        $this->chainDropped = ($data['chainDropped'] ?? null) === true;

        (new ReflectionProperty(Exception::class, 'trace'))
            ->setValue($this, is_array($trace) ? $this->safeFrames($trace) : []);
    }

    /**
     * Narrows a trace to the keys that name code rather than values, and to the
     * types those keys are supposed to hold.
     *
     * A positive filter, not a blacklist. `args` is the key that carries a
     * payload today; `object` is not one `Exception::getTrace()` produces at all
     * — only `debug_backtrace()` supplies it, and only when asked — so it is not
     * named here as a thing being dropped. It does not need to be: a frame is a
     * structure this package does not own, so an unknown key that survives is a
     * leak, while one that is dropped is a slightly thinner diagnostic. Anything
     * the engine adds later — an `object` key included, should one ever appear —
     * is therefore dropped by default rather than published by default.
     *
     * Values are checked against the type their key is read back as, and a key
     * holding anything else is dropped like an unknown one. Inbound, this runs
     * over whatever a payload claimed, and `getTraceAsString()` reads the frames
     * back positionally: a `file` that is not a string or a `line` that is not
     * an int raises a PHP warning there, which Laravel's error handler turns
     * into an ErrorException. Narrowing by name alone would not prevent the
     * fatal `__unserialize()` refuses to raise, only defer it to the first log
     * formatter that read the trace. The frame itself survives a dropped key.
     *
     * One narrowing serves both directions rather than an inbound-only second
     * one. Outbound the type check is satisfied by construction, since the
     * engine types the frames it builds — an always-true branch there is the
     * price of there being a single definition of what a safe frame is.
     *
     * The key list is a local rather than a class constant deliberately. A
     * constant is not an executable statement, so it never appears in a
     * coverage report and every mutant on it is classified uncovered without a
     * test having been run — which puts MSI 100 out of reach for a reason that
     * has nothing to do with the code being tested.
     *
     * Frames that are not arrays are skipped rather than rejected: this runs on
     * the inbound path too, where the input is whatever a payload claimed, and
     * that path must not throw.
     *
     * @param  array<array-key, mixed>  $trace
     * @return list<array<array-key, mixed>>
     */
    private function safeFrames(array $trace): array
    {
        $safeFrameKeys = array_flip(['file', 'line', 'function', 'class', 'type']);
        $safe = [];

        foreach ($trace as $frame) {
            if (is_array($frame)) {
                $narrowed = [];

                foreach (array_intersect_key($frame, $safeFrameKeys) as $key => $value) {
                    if ($key === 'line' ? is_int($value) : is_string($value)) {
                        $narrowed[$key] = $value;
                    }
                }

                $safe[] = $narrowed;
            }
        }

        return $safe;
    }
}
