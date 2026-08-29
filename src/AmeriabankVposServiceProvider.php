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
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function is_int;
use function is_string;

/**
 * Wires the Ameriabank vPOS client into a Laravel application.
 *
 * Five bindings, one configuration file, and nothing else. The client already
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
     * The configuration namespace, and the published file's base name.
     */
    private const string CONFIG_KEY = 'ameriabank-vpos';

    /**
     * The publish tag a merchant passes to `vendor:publish`.
     */
    private const string CONFIG_TAG = 'ameriabank-vpos-config';

    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), self::CONFIG_KEY);

        $this->app->singleton(Vpos::class, fn (Application $app): Vpos => $this->makeVpos($app));

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
            static fn (Application $app): VposCallback => self::makeCallback($app),
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
            [$this->configPath() => $this->app->configPath(self::CONFIG_KEY.'.php')],
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
        return __DIR__.'/../config/'.self::CONFIG_KEY.'.php';
    }

    /**
     * Builds the one client the application gets.
     *
     * The PSR-18 client is taken from the container when something has bound
     * one — which is how a merchant chooses Guzzle explicitly, and how a test
     * substitutes a mock — and left null otherwise, so the client's own
     * discovery runs and a failed discovery still surfaces as the core's
     * ConfigurationException rather than as a bare discovery error.
     *
     * The credentials are built before anything else is evaluated, so a blank
     * one is refused by the core at the earliest possible point and no
     * half-configured client is ever constructed.
     */
    private function makeVpos(Application $app): Vpos
    {
        $config = $app->make(Repository::class);

        return new Vpos(
            credentials: new Credentials(
                clientId: $this->configString($config, 'client_id'),
                username: $this->configString($config, 'username'),
                password: $this->configString($config, 'password'),
            ),
            environment: $this->environment($config),
            httpClient: $app->bound(ClientInterface::class) ? $app->make(ClientInterface::class) : null,
            logger: $this->logger($config),
            maxAttempts: $this->configInt($config, 'max_attempts'),
        );
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
     * @throws ConfigurationException when this request carries no readable vPOS callback
     */
    private static function makeCallback(Application $app): VposCallback
    {
        try {
            return VposCallback::fromQuery($app->make(Request::class)->query->all());
        } catch (ValidationException $failure) {
            throw ConfigurationException::callbackOutsideRequest($failure);
        }
    }

    /**
     * The configured environment, or a refusal naming what was configured.
     *
     * @throws ConfigurationException on any value the client does not know
     */
    private function environment(Repository $config): Environment
    {
        $value = $this->configString($config, 'environment');

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
    private function logger(Repository $config): LoggerInterface
    {
        if ($config->get(self::CONFIG_KEY.'.logging.enabled') !== true) {
            return new NullLogger;
        }

        $channel = $this->configString($config, 'logging.channel');

        return Log::channel($channel === '' ? null : $channel);
    }

    /**
     * A package configuration value as a string.
     *
     * Anything that is not a string — a missing key, an unset environment
     * variable arriving as null — reads as blank, and blank is refused by
     * whichever component was asked for it. The key is the argument here and
     * the value never is, so no credential is ever a stack frame's argument in
     * this class.
     */
    private function configString(Repository $config, string $key): string
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key);

        return is_string($value) ? $value : '';
    }

    /**
     * A package configuration value as an int.
     *
     * Anything that is not an int reads as 0, which the client rejects as
     * outside its 1..5 attempt budget. Coercing instead would turn a
     * misconfigured `max_attempts` into a silently different one.
     */
    private function configInt(Repository $config, string $key): int
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key);

        return is_int($value) ? $value : 0;
    }
}
