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
 * for outside the request that would carry it, a key set to the wrong type, an
 * attempt budget outside the range the client accepts.
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
     * The code is always 0, so nothing downstream has to decide whether this
     * package's codes mean anything. They do not.
     *
     * **The core package agrees, with one carve-out worth naming** for anyone
     * writing a single catch block over both packages. Every class in the
     * core's `Exception/` namespace passes 0 or passes no code at all, and
     * those are the classes `VposExceptionInterface` marks. Its three
     * `Http/Redacted*` stand-ins — `RedactedNetworkException`,
     * `RedactedRequestException` and `RedactedClientException` — instead take
     * an `int $code` and pass it straight to `RuntimeException`, fed from
     * whatever code the consumer's own PSR-18 client set. `getCode()` is
     * meaningful on those three and nowhere else across the two packages. They
     * implement PSR-18's exception interfaces rather than
     * `VposExceptionInterface`, so a catch on the marker interface does not
     * see them at all.
     *
     * That paragraph is a claim about a package this one does not control, and
     * nothing in this package's gate reads the core's method bodies, so it is
     * written as what was measured rather than as a property. Measured against
     * `davit-vardanyan/ameriabank-vpos-php` v1.0.1: `src/Exception/*` pass 0 or
     * nothing, and `src/Http/RedactedNetworkException.php:59`,
     * `RedactedRequestException.php:45` and `RedactedClientException.php:48`
     * each read `parent::__construct($message, $code)`.
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

    /**
     * A configured value is set, and is set to something that is not a string.
     *
     * Distinct from a value being absent, and that distinction is the whole
     * point of this factory. A missing key and an unset environment variable
     * both arrive as `null` and are read as blank, which the component asked
     * for the value refuses in its own words — *"Credential field ClientID
     * must not be blank"* — and that refusal is correct, because the value
     * really is not there. Reading a wrong-typed value as blank sends the same
     * refusal for a value that **is** there, and an operator told a key is
     * missing goes looking for a missing one.
     *
     * Refused rather than cast. A cast turns a misconfigured value into a
     * silently different one, and for a credential a silently different one is
     * a credential the gateway will reject without saying which field was
     * wrong.
     *
     * **Only the type is named, and the value never crosses into this class.**
     * The value cannot be repeated, cannot be truncated, and cannot be
     * described by anything derived from it. `get_debug_type()` names the type
     * and nothing else — it returns `int`, `bool`, `array`, `float` or a class
     * name, none of which is a function of the value.
     *
     * That treatment assumes the key may hold a credential, and today it does:
     * the sole caller is `ConfigReader::string()`, which reads under this
     * package's own configuration namespace, where three keys hold the
     * ClientID, the username and the password. **The assumption is the reason
     * for the treatment, not a property of this class.** A second caller
     * reading some other namespace would make the assumption wrong and change
     * nothing about what the parameters do, and nothing here would go red:
     * this class cannot constrain where a key comes from, and the defence is
     * in the signature rather than in the provenance.
     *
     * **The caller resolves the type; this factory is handed the name.** A
     * factory taking the value would keep it out of the message and put it into
     * the trace: the exception is constructed inside this method, so this
     * method's own call becomes frame 0 of `getTrace()`, its arguments become
     * `args`, and `getTraceAsString()` renders them verbatim. Laravel's default
     * log formatter includes stack traces, so a reported exception would write
     * a credential into the application's log — the one place the rest of this
     * class is written to keep it out of. Narrowing the parameter to `string`
     * removes the value from the boundary rather than filtering it afterwards,
     * and it is the half of that defence `__serialize()` cannot supply, since
     * `__serialize()` never runs for an exception that is merely logged.
     *
     * No environment variable is named here, unlike most factories on this
     * class. The mapping from a configuration key to the variable behind it is
     * not derivable — `logging.channel` is read from `AMERIABANK_VPOS_LOG_CHANNEL`
     * and `logging.enabled` from `AMERIABANK_VPOS_LOGGING` — so a name composed
     * from the key would be right for the credentials and wrong for the
     * logging keys, in a message whose whole purpose is to stop sending the
     * operator to the wrong place.
     *
     * @param  string  $key  The configuration key, relative to the package namespace. Never a value.
     * @param  string  $type  The configured value's type, from `get_debug_type()` at the throw site. Never a value.
     */
    public static function notAString(string $key, string $type): self
    {
        return new self(sprintf(
            'ameriabank-vpos.%s must be a string, and the configured value is of type %s. It is not missing — '
            .'it is set to the wrong type, and this package refuses it rather than casting it, because a cast '
            .'would turn a misconfigured value into a silently different one. Neither the value nor any part of '
            .'it is repeated here, because these keys can hold credentials. Correct the type in '
            .'config/ameriabank-vpos.php, or in the environment variable that key reads.',
            $key,
            $type,
        ));
    }

    /**
     * `max_attempts` is not an integer, or is an integer outside the client's
     * accepted range.
     *
     * Checked on this side of the bridge rather than left to the client. The
     * client refuses the same value, but it refuses a *number*, having no idea
     * where that number came from, so its message can name neither the
     * configuration key nor the environment variable behind it. Where the
     * client refuses it is a fact about the core, and nothing in this package's
     * gate reads the core's method bodies, so it is stated as the measurement
     * it is: on the installed `^1.0.1`,
     * `src/Http/HttpTransport.php:306-307` raises `ValidationException` from the
     * transport's constructor. What this package *holds* is narrower and is
     * held by `tests/Arch/AttemptBudgetBoundsTest.php`: the range accepted here
     * is exactly `HttpTransport::MINIMUM_ATTEMPTS..MAXIMUM_ATTEMPTS`, read by
     * reflection at test time. That the client enforces those constants is
     * deliberately not asserted anywhere.
     * This value comes from `ameriabank-vpos.max_attempts` and from nowhere
     * else, so this package can say exactly what to change, and does.
     *
     * A non-integer is reported as its type rather than as the `0` a cast
     * would produce. `(int) 'three'` is `0` and `(int) '3.9'` is `3`: the first
     * names a budget nobody configured and the second silently runs a
     * different one.
     *
     * **The bounds are parameters, not literals in this message.** The check
     * that rejects the value and the sentence that explains the rejection have
     * to agree, and the only way to guarantee that is for both to read the same
     * two numbers. They belong to the caller because the caller is what
     * compares against them.
     *
     * The configured value itself is not interpolated, for the reason
     * `notAString()` gives: the operator already has it, and this package does
     * not echo configuration back into a message it did not have to.
     *
     * **The value does not reach this method either**, for the reason
     * `notAString()` gives at greater length: a factory's own parameters become
     * frame 0's `args` in the trace the exception it builds carries, and that
     * trace is logged. `max_attempts` is not a credential, so this one is
     * consistency rather than exposure — and the exposure is not hypothetical:
     * `notAString(string $key, mixed $actual)` shipped in exactly this shape,
     * kept the configured value out of its message, and put a password set to
     * an array into `getTrace()[0]['args']` and from there verbatim into
     * `storage/logs/laravel.log`. That is the demonstration this signature
     * rests on, and `tests/Arch/ExceptionFactorySignatureTest.php` is what
     * keeps the shape unrepresentable across every factory on this class.
     *
     * Which of the two faults occurred is therefore decided from the type name
     * rather than from the value: `get_debug_type()` returns `int` for exactly
     * the values `is_int()` accepts, so an `int` arriving here is one the
     * caller compared against the bounds and rejected.
     *
     * **That last sentence is about the caller, not about this class.** The
     * only caller in `src/` is `AmeriabankVposServiceProvider::maxAttempts()`,
     * which compares the value against the same two bounds it then passes in.
     * `tests/Arch/AttemptBudgetBoundsTest.php` also calls it, to rebuild the
     * expected message from the client's own constants; the bounds it hands
     * over come from reflection and are compared against nothing, so it is
     * already the second caller the next sentence describes — it asserts the
     * sentence rather than relying on it. A second caller in `src/` handing
     * over an unchecked `int` would make the sentence false, and nothing here
     * would go red: this class cannot constrain a caller, and states the range
     * branch's premise rather than guaranteeing it.
     *
     * @param  string  $type  The configured value's type, from `get_debug_type()` at the throw site. Never a value.
     * @param  int  $lowestBudget  The smallest accepted attempt count, from the caller that enforces it.
     * @param  int  $highestBudget  The largest accepted attempt count, from the same caller.
     */
    public static function invalidMaxAttempts(string $type, int $lowestBudget, int $highestBudget): self
    {
        $fault = $type === 'int'
            ? 'the configured value is outside that range'
            : sprintf(
                'the configured value is of type %s, which this package refuses rather than casting, because a '
                .'cast would run an attempt budget nobody configured',
                $type,
            );

        return new self(sprintf(
            'ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) must be an integer between %d and %d, '
            .'and %s. The value itself is not repeated here. This is the total number of attempts a retryable '
            .'operation gets; which operations may be retried at all is fixed by the client and is not '
            .'configurable.',
            $lowestBudget,
            $highestBudget,
            $fault,
        ));
    }

    /**
     * The package-scoped HTTP client key is bound to something that is not a
     * PSR-18 client.
     *
     * This key exists so that an application can name the client the vPOS
     * credential payload goes through, distinctly from the application-wide
     * `Psr\Http\Client\ClientInterface` binding that every other package sees.
     * Something bound there is therefore a deliberate instruction, and the
     * quiet answer — ignore it, fall back to the shared binding — would send
     * the payment traffic to exactly the client the application went out of its
     * way to say it did not want. It is refused instead, at the first
     * resolution, in the application's own service provider's words.
     *
     * The key is a parameter rather than a literal here for the reason
     * `invalidMaxAttempts()` gives about its bounds: the code that reads the
     * key and the sentence that names it have to agree, and they agree by
     * reading the same constant.
     *
     * Only the type is named, by `get_debug_type()` at the throw site. Nothing
     * bound to a container key is assumed safe to print — a closure's return, a
     * misconfigured factory or a string holding a token are all things that
     * could arrive here.
     *
     * **Nor safe to accept.** Printing is not the only way a value escapes: a
     * factory taking the bound object would make it frame 0's argument in the
     * trace the refusal carries, and that trace is logged. So the type is
     * resolved by the caller and the object stays there, for the reason
     * `notAString()` sets out.
     *
     * @param  string  $key  The container key that was read. Never a value.
     * @param  string  $type  The bound value's type, from `get_debug_type()` at the throw site. Never a value.
     */
    public static function httpClientNotPsr18(string $key, string $type): self
    {
        return new self(sprintf(
            'The container key %s is bound to a value of type %s, which does not implement '
            .'Psr\Http\Client\ClientInterface. That key is where this package looks for the PSR-18 client to '
            .'send Ameriabank vPOS traffic through, so it is refused rather than ignored: falling back to the '
            .'application-wide Psr\Http\Client\ClientInterface binding would send payment traffic through a '
            .'client this application asked it not to use. Bind a PSR-18 client there, or unbind the key and '
            .'let the application-wide binding or the client\'s own discovery choose.',
            $key,
            $type,
        ));
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
     * **This covers one of the two paths a trace leaves by, and the other one
     * is not defended here.** `__serialize()` runs when an exception is queued,
     * cached or otherwise persisted. It does not run when one is merely
     * *logged*: Laravel's default log formatter reads `getTraceAsString()` off
     * the live object, and `getTrace()` on a live object still carries every
     * frame's `args`, this filter notwithstanding. What keeps a credential out
     * of that trace is which arguments the factories above accept: every
     * factory a credential-bearing key can reach takes the type name its caller
     * resolved and never the value, so no credential is an argument to a frame
     * this class builds. The factories that do take a configured value verbatim
     * are handed `environment` or `back_url`; neither is a credential, and each
     * of those factories interpolates the value it was given into the message
     * it builds, so a configured value in one of those frames' arguments is one
     * the exception's own message already prints. Neither half is sufficient
     * alone, and a reader who finds only this one will conclude the other is
     * unnecessary: it is not.
     *
     * `previous` is dropped, and that it was dropped is recorded rather than
     * silently lost — that is what the `chainDropped` key is.
     *
     * The reason is the opening paragraph's, and it is this class's own: a chain
     * is an object this package did not build, reachable only through code it
     * does not own, so listing it would put back exactly the unbounded state
     * that naming the state explicitly removes. Only three factories take one —
     * `unresolvableBackUrlRoute()`, `parameterisedBackUrlRoute()` and
     * `callbackOutsideRequest()` — and their causes come from Laravel's URL
     * generator and from the client, neither of which this package can bound.
     *
     * The core drops it too, and its reason is **not** borrowed, because it does
     * not transfer. Measured on the installed `^1.0.1`,
     * `src/Support/ExceptionState.php:49-52` drops `previous` because a transport
     * failure's chain is a PSR-18 exception or a scrubbed stand-in for one, and
     * both hand back the request they were sent, whose body is the merged
     * credential payload. No chain of that shape reaches this class: none of the
     * three factories above is on a transport path. Citing the core's reason
     * here would be holding this package's decision up with an argument about
     * somebody else's code that nothing in this gate reads.
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
     * — measured on PHP 8.3.28 and on 8.5.7, from inside an instance method
     * taking an object argument, under both `zend.exception_ignore_args`
     * settings, with no frame carrying the key on any of the four runs. Only
     * `debug_backtrace()` supplies it, and it supplies it **by default**:
     * `DEBUG_BACKTRACE_PROVIDE_OBJECT` is the default `$options`, and the key
     * is omitted only when a caller asks for that by passing `0` or
     * `DEBUG_BACKTRACE_IGNORE_ARGS`. `object` is therefore not named here as a
     * thing being dropped. It does not need to be: a frame is a structure this
     * package does not own, so an unknown key that survives is a leak, while
     * one that is dropped is a slightly thinner diagnostic. Anything
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
