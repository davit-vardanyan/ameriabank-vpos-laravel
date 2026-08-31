<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel;

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Client\BindingsClient;
use DavitVardanyan\AmeriabankVpos\Client\PaymentsClient;
use DavitVardanyan\AmeriabankVpos\Client\ReportsClient;
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Commands\CheckCommand;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\ConfigReader;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function get_debug_type;
use function is_int;

/**
 * Wires the Ameriabank vPOS client into a Laravel application.
 *
 * Six bindings, one configuration file, and nothing else. The client already
 * knows how to talk to the gateway; this provider's whole job is to build it
 * once from `config/ameriabank-vpos.php` and to make its parts injectable.
 *
 * ## What is bound, and why in that shape
 *
 * `Vpos` is a **singleton** — it owns the transport and the discovered PSR-18
 * client, and one application wants one of those. The three operation clients
 * are bound **non-singleton** and resolved from that singleton, so
 * `__construct(private PaymentsClient $payments)` works in a controller without
 * the controller having to know a composition root exists. Binding them as
 * singletons would add nothing: they are thin handles the singleton already
 * holds, and a second layer of caching would only be a second thing to reason
 * about when a test swaps one.
 *
 * `VposCallback` is bound from the current request's query string, so a
 * controller handling the BackURL can take it as a parameter. It is untrusted
 * data with a type, not a verdict — `Vpos::verify()` is the only thing that
 * establishes what actually happened to a payment.
 *
 * `BackUrlResolver` is bound **non-singleton**, and the binding is written out
 * rather than left to autowiring. It owns nothing — no transport, no
 * connection, no cached value — so there is no resource for a singleton to
 * share, and it reads `back_url` inside `resolve()` from whichever
 * configuration repository the container holds at that moment. Caching the
 * object would pin the repository it was constructed with, so an application
 * that replaces its configuration between resolutions — a long-lived worker
 * reloading it, a test rebinding `config` as an instance — would keep
 * resolving the BackURL of a configuration that is no longer in force. That is
 * the same silent divergence between the configured value and the sent one
 * that making this class public API exists to close, so the cheaper failure is
 * to rebuild two constructor arguments per call. Writing the binding out is
 * what makes it a documented seam rather than an accident of the class being
 * autowirable: `bound()` answers true for it, and a future constructor
 * argument the container cannot guess fails here instead of at a call site.
 *
 * ## Nothing is validated while the application boots
 *
 * Every read of configuration below happens inside a closure, so an application
 * with no vPOS credentials still boots, still migrates and still runs its queue.
 * A missing credential surfaces the first time something asks for the client,
 * which is the request that needed it.
 *
 * ## The environment is never guessed
 *
 * An unrecognised value throws and names itself. Defaulting it either points a
 * live shop at the sandbox — taking no money, and finding out at reconciliation
 * weeks later — or points a developer's machine at production. Neither failure
 * is worth the convenience of a default.
 */
final class AmeriabankVposServiceProvider extends ServiceProvider
{
    /**
     * The publish tag a merchant passes to `vendor:publish`.
     */
    private const string CONFIG_TAG = 'ameriabank-vpos-config';

    /**
     * The container key this package looks for its own PSR-18 client under.
     *
     * A **container** key, not a configuration path. It is public so that the
     * string is cited rather than retyped — an application binds
     * `AmeriabankVposServiceProvider::HTTP_CLIENT_KEY` and a test asserts
     * against the same constant, so a typo is a fatal at the call site instead
     * of a binding that is silently never found.
     *
     * It is written out in full rather than composed from
     * `ConfigReader::CONFIG_KEY`. A
     * class constant whose initialiser is a concatenation is mutated by
     * `pest-plugin-mutate` and can never be covered by it — a `const` is not an
     * executable statement, so it appears in no coverage report and every
     * mutant on it is classified uncovered without a test having been run. One
     * literal is the price of the floor staying reachable.
     *
     * @see AmeriabankVposServiceProvider::httpClient() for what is done with it
     */
    public const string HTTP_CLIENT_KEY = 'ameriabank-vpos.http-client';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), ConfigReader::CONFIG_KEY);

        $this->app->singleton(Vpos::class, fn (): Vpos => $this->makeVpos());

        $this->app->bind(
            PaymentsClient::class,
            static fn (Application $app): PaymentsClient => $app->make(Vpos::class)->payments(),
        );

        $this->app->bind(
            BindingsClient::class,
            static fn (Application $app): BindingsClient => $app->make(Vpos::class)->bindings(),
        );

        $this->app->bind(
            ReportsClient::class,
            static fn (Application $app): ReportsClient => $app->make(Vpos::class)->reports(),
        );

        $this->app->bind(
            VposCallback::class,
            fn (): VposCallback => $this->makeCallback(),
        );

        $this->app->bind(
            BackUrlResolver::class,
            static fn (Application $app): BackUrlResolver => new BackUrlResolver(
                $app->make(Repository::class),
                $app->make(UrlGenerator::class),
            ),
        );
    }

    /**
     * Publishes the configuration, and offers the credential check to artisan.
     *
     * `publishes()` is deliberately not guarded: the publish map is inert data
     * that costs nothing to register and answers `vendor:publish --list` from
     * any entry point. The command registration is guarded, because
     * `commands()` reaches for the artisan application and there is no reason
     * for a web request to have opinions about console commands.
     */
    public function boot(): void
    {
        $this->publishes(
            [$this->configPath() => $this->app->configPath(ConfigReader::CONFIG_KEY.'.php')],
            self::CONFIG_TAG,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class]);
        }
    }

    /**
     * The packaged configuration file, both merged and published from here.
     */
    private function configPath(): string
    {
        return __DIR__.'/../config/'.ConfigReader::CONFIG_KEY.'.php';
    }

    /**
     * Builds the one client the application gets.
     *
     * The PSR-18 client is chosen by `httpClient()` below, from a
     * package-scoped binding, then an application-wide one, then not at all.
     *
     * The credentials are built before anything else is evaluated, so a blank
     * one is refused by the core at the earliest possible point and no
     * half-configured client is ever constructed.
     *
     * The attempt budget is validated here rather than handed to the client to
     * refuse. Every argument is evaluated before `Vpos::__construct()` is
     * entered, so a refusal from `maxAttempts()` happens while there is still
     * no Vpos, no HttpTransport and nothing holding the bad value — which is
     * what makes the client's own `ValidationException` unreachable for this
     * cause rather than merely pre-empted.
     *
     * **Every method this class declares reaches the container through `$this`
     * and takes neither it nor the configuration repository as an argument.**
     * That is what keeps `Object(Illuminate\Config\Repository)` — and the
     * container it can be reached through — out of the frames these methods
     * contribute to the refusals they raise. `makeCallback()` below is why this
     * is stated rather than assumed: it reads no configuration, so it was left
     * taking the container while the rest of this class was narrowed, and its
     * refusal put `Illuminate\Foundation\Application` into frame 1 of a trace
     * that reaches the application's exception handler. Reading configuration
     * was never the test; handing out something that reaches it is.
     *
     * The sentence is about the frames this package writes, and those can be
     * told apart from the rest by name. A frame naming a method of this class
     * is one whose parameter list is declared here. The first frame that is not
     * is `AmeriabankVposServiceProvider::{closure}` — the closure `register()`
     * hands to `bind()` — and it records
     * `(Illuminate\Foundation\Application, array)` whatever that closure
     * declares: measured on the `Vpos` binding, whose closure declares no
     * parameters and whose frame carries both regardless, because the container
     * passes itself to everything it builds from. No parameter list removes
     * that frame, so it is residue rather than something this class fixed.
     * `ConfigReader` sets out the measurement and what does close it, which is
     * not this package.
     *
     * @throws ConfigurationException on a key set to the wrong type, an unknown
     *                                environment, an attempt budget the client would not accept, or a
     *                                package-scoped HTTP client binding that is not a PSR-18 client
     */
    private function makeVpos(): Vpos
    {
        $config = $this->packageConfig();

        return new Vpos(
            credentials: new Credentials(
                clientId: $config->string('client_id'),
                username: $config->string('username'),
                password: $config->string('password'),
            ),
            environment: $this->environment(),
            httpClient: $this->httpClient(),
            logger: $this->logger(),
            maxAttempts: $this->maxAttempts(),
        );
    }

    /**
     * The PSR-18 client the vPOS credential payload will be sent through.
     *
     * Three tiers, tried in this order, and the order is the whole point:
     *
     * 1. `ameriabank-vpos.http-client` — a container key owned by this package
     *    and read by nothing else, so a client bound under it was bound *for*
     *    the gateway;
     * 2. `Psr\Http\Client\ClientInterface` — the application-wide PSR-18 key.
     *    Kept, because it is what this package has always used and removing it
     *    would break every application that binds it;
     * 3. `null` — nothing is chosen, the core's `php-http/discovery` runs, and
     *    a failed discovery still surfaces as the core's own
     *    ConfigurationException rather than as a bare discovery error.
     *
     * Tier 2 is the reason tier 1 exists. `Container::bound()` answers true for
     * a binding, an instance **or an alias**, and `ClientInterface` is a shared
     * key any package in the application may claim. A client bound there for
     * some other API — carrying a base URI, default headers, an auth middleware
     * or a proxy — would be handed this package too, and the credential payload
     * would go out through it. Discovery would also pick something, but
     * discovery picks an *unconfigured* client; a container binding is far more
     * likely to be configured for somewhere else entirely. Tier 1 gives an
     * application a way to say which client is meant for the bank without
     * unbinding the one it has.
     *
     * **Contextual binding is not available here, and trying it is wasted
     * work: this provider constructs `Vpos` with `new` rather than resolving it
     * through the container, so `when(Vpos::class)->needs(ClientInterface::class)`
     * is never consulted — the container is never the thing building `Vpos`,
     * and only the container fires a contextual binding.** Scoping therefore
     * has to be a key this method reads itself, which is what tier 1 is.
     *
     * A tier 1 binding that is not a PSR-18 client is refused by name rather
     * than skipped. Falling through to tier 2 would send the payment traffic
     * somewhere the application explicitly said it did not want it to go, and
     * would do it silently; the mistake is in the application's own service
     * provider and it should hear about it the first time the client resolves.
     *
     * The refusal is handed the resolved value's *type*, not the value. What is
     * bound there is arbitrary — a factory's return, a client wrapping an API
     * token — and a factory taking it would put it in frame 0 of the trace the
     * exception carries, which is logged. `ConfigReader` gives the full
     * reasoning; this site follows it, and takes no argument for the same
     * reason: the container is reached through `$this`, so the refusal's frame
     * 1 carries nothing a reporter could walk into the credentials through.
     *
     * @throws ConfigurationException when the package-scoped key is bound to something
     *                                that does not implement PSR-18
     */
    private function httpClient(): ?ClientInterface
    {
        if ($this->app->bound(self::HTTP_CLIENT_KEY)) {
            $scoped = $this->app->make(self::HTTP_CLIENT_KEY);

            if ($scoped instanceof ClientInterface) {
                return $scoped;
            }

            throw ConfigurationException::httpClientNotPsr18(self::HTTP_CLIENT_KEY, get_debug_type($scoped));
        }

        if ($this->app->bound(ClientInterface::class)) {
            return $this->app->make(ClientInterface::class);
        }

        return null;
    }

    /**
     * Reads the callback the gateway put on the BackURL.
     *
     * The core parses the query string, pins the five wire spellings — the
     * lowercase `paymentID` the gateway actually sends included — and refuses a
     * callback carrying no identifiers. Its refusal names the missing field,
     * which is the right answer for a malformed callback and the wrong one for
     * a console command that asked for a callback there was never going to be,
     * so the refusal is wrapped in a message that supplies the context and
     * keeps the original as its cause.
     *
     * Takes no argument and is not static, for the reason `makeVpos()` sets
     * out: it reads no configuration, but the container it was handed reaches
     * the credentials in one hop, and this is the refusal that escapes to the
     * application's exception handler.
     *
     * @throws ConfigurationException when this request carries no readable vPOS callback
     */
    private function makeCallback(): VposCallback
    {
        try {
            return VposCallback::fromQuery($this->app->make(Request::class)->query->all());
        } catch (ValidationException $failure) {
            throw ConfigurationException::callbackOutsideRequest($failure);
        }
    }

    /**
     * The configured environment, or a refusal naming what was configured.
     *
     * @throws ConfigurationException on any value the client does not know, and
     *                                on a key set to something other than a string
     */
    private function environment(): Environment
    {
        $value = $this->packageConfig()->string('environment');

        return Environment::tryFrom($value)
            ?? throw ConfigurationException::unknownEnvironment($value);
    }

    /**
     * The logger the client writes to — off unless it was switched on.
     *
     * A NullLogger rather than a disabled channel, so nothing is formatted,
     * filtered or handed to a handler at all. `logging.enabled` is compared by
     * identity: anything that is not exactly `true` leaves logging off, which is
     * the direction a payment package should fail in.
     *
     * A configured channel is resolved through the Log facade because that is
     * the only route to a *named* channel that `illuminate/support` exposes; a
     * blank channel name resolves the application's default channel.
     */
    private function logger(): LoggerInterface
    {
        $config = $this->packageConfig();

        if ($config->value('logging.enabled') !== true) {
            return new NullLogger;
        }

        $channel = $config->string('logging.channel');

        return Log::channel($channel === '' ? null : $channel);
    }

    /**
     * A reader over the configuration repository the container holds *now*.
     *
     * This provider used to carry its own eight-line `configString()`, and
     * `vpos:check` carried a byte-identical one under a docblock stating the
     * coupling as a requirement. `ConfigReader` is that reader, extracted, so
     * the requirement holds by construction rather than by two docblocks
     * agreeing; its own docblock carries the reasoning that used to live here.
     *
     * What is narrower than before, and deliberately so: `configString()`
     * carried the configuration repository and the key in its own frame, and
     * said so. `ConfigReader::string()` carries the key alone, because the
     * repository sits on the reader rather than in its parameter list and
     * `Exception::getTrace()` records no `object` key. That is a narrowing of
     * the claim, not a widening of it — the sentence about frames this package
     * did not build still stands, and the container still reaches the closure
     * this provider registers, which is where the residue now lives.
     *
     * A fresh reader per call rather than a cached one. Caching would pin the
     * repository the first read happened to see, and `Vpos` can be built more
     * than once in a process — a queue worker reloading configuration, a test
     * calling `forgetInstance(Vpos::class)` after rebinding `config` — so a
     * cached reader would build the client from credentials no longer in
     * force. It is the hazard `BackUrlResolver` is bound rather than shared to
     * avoid, and it takes the same answer here.
     */
    private function packageConfig(): ConfigReader
    {
        return new ConfigReader($this->app->make(Repository::class));
    }

    /**
     * The configured attempt budget, refused here rather than by the client.
     *
     * The client bounds this at 1..5 and raises its own `ValidationException`
     * from the transport's constructor when a value is outside that. Letting it
     * do so costs the merchant the only thing they need: the client is handed a
     * number with no idea where it came from, so its message names no
     * configuration key and no environment variable. This value comes from
     * `ameriabank-vpos.max_attempts` and from nowhere else, so this side of the
     * bridge can name both, and refusing before `new Vpos(...)` is entered
     * means the client's refusal is never raised for this cause at all.
     *
     * A non-integer is refused rather than read as `0`. Zero was out of range
     * and so was refused anyway, but by a message naming a number nobody
     * configured. The type is resolved here and the value is not passed on, for
     * the reason `ConfigReader` gives: a factory's parameters become frame 0's
     * arguments in the trace its exception carries. `max_attempts` is not a
     * credential; the shape of the call is the same one either way.
     *
     * **The bounds are named locals rather than class constants.** A `const`
     * declaration is not an executable statement, so it appears in no coverage
     * report and the mutation tool classifies every mutant on its initialiser
     * as uncovered without running a test. As locals they sit on a covered
     * line, and they are handed to the factory as well as compared against, so
     * a mutant that moves either bound changes both the accepted range and the
     * printed one.
     *
     * They duplicate a bound the client owns. The client keeps it private
     * (`HttpTransport::MINIMUM_ATTEMPTS` and `MAXIMUM_ATTEMPTS`), which is not
     * the same as unreadable: `ReflectionClassConstant::getValue()` reads a
     * private constant, and `tests/Arch/AttemptBudgetBoundsTest.php` reads both
     * at test time and asserts that the budgets this provider actually accepts
     * are exactly the client's range, and that the refusal prints that same
     * pair. So the duplication is held rather than merely argued for, and the
     * day the client moves a bound this package goes red instead of drifting.
     *
     * The safe direction is the fallback behind that guard rather than the only
     * protection: if the two ever did diverge in a running application, a value
     * this check accepts and the client refuses arrives as the client's own
     * `ValidationException` and is reported by
     * `CheckCommand::configuredValueRefused()` in the client's words, which is
     * the generic branch's purpose.
     *
     * @throws ConfigurationException when the key is not an integer, or is outside the accepted range
     */
    private function maxAttempts(): int
    {
        $lowestBudget = 1;
        $highestBudget = 5;

        $value = $this->packageConfig()->value('max_attempts');

        if (! is_int($value) || $value < $lowestBudget || $value > $highestBudget) {
            throw ConfigurationException::invalidMaxAttempts(get_debug_type($value), $lowestBudget, $highestBudget);
        }

        return $value;
    }
}
