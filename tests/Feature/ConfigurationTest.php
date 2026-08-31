<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException as ClientConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ClientInternals;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

/**
 * The message a refusal carried, for the assertions that are about absence.
 *
 * `toThrow()` asserts a message *contains* what it is given, which settles what
 * a refusal says and nothing about what it does not. The wrong-typed keys are
 * defined as much by what their messages withhold — the configured value, the
 * old blank-reading sentence, the `0` a cast used to produce — and that half
 * needs the message in hand.
 *
 * A run that throws nothing is a refusal rather than an empty string: an
 * absence assertion over "" passes for every needle there is, which is the
 * vacuous direction.
 */
function refusalFor(Closure $resolve): string
{
    try {
        $resolve();
    } catch (Throwable $failure) {
        return $failure->getMessage();
    }

    throw new RuntimeException(
        'Nothing was refused, so there is no message to assert against. An absence assertion over an empty '
        .'string passes whatever it is given, which is the direction that hides a leak.',
    );
}

beforeEach(function (): void {
    vposConfig();

    Config::set('logging.channels.vpos_probe', [
        'driver' => 'single',
        'path' => storage_path('logs/vpos-probe-that-is-never-written.log'),
    ]);
});

it('refuses an environment the client does not know, naming the value', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'Unknown Ameriabank vPOS environment "staging". Set ameriabank-vpos.environment '
        .'(AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.',
    );
});

/*
 * The wrong-typed environment, which used to be reported as a blank one.
 *
 * `configString()` read anything that was not a string as `''`, so a numeric
 * environment arrived at `Environment::tryFrom('')` and produced *Unknown
 * Ameriabank vPOS environment ""* — a message about a value nobody configured,
 * over a key that is plainly set. The type is now named as the mistake it is,
 * and the old message is asserted absent so that a regression to the blank
 * reading cannot pass here.
 */
it('refuses an environment set to something that is not a string, naming its type', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 42]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.environment must be a string, and the configured value is of type int. It is not '
        .'missing — it is set to the wrong type, and this package refuses it rather than casting it, because a '
        .'cast would turn a misconfigured value into a silently different one. Neither the value nor any part '
        .'of it is repeated here, because these keys can hold credentials. Correct the type in '
        .'config/ameriabank-vpos.php, or in the environment variable that key reads.',
    );

    expect(refusalFor(fn (): Vpos => app(Vpos::class)))
        ->not->toContain('Unknown Ameriabank vPOS environment ""');
});

it('builds a client for either environment the package knows', function (string $environment): void {
    vposConfig(['ameriabank-vpos.environment' => $environment]);

    expect(app(Vpos::class))->toBeInstanceOf(Vpos::class);
})->with(['test', 'production']);

it('refuses a credential the application never configured', function (): void {
    vposConfig(['ameriabank-vpos.client_id' => null]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ClientConfigurationException::class,
        'Credential field "ClientID" must not be blank.',
    );
});

/*
 * A credential set to the wrong type is a different mistake from a credential
 * that is not there, and it is now reported as itself.
 *
 * It used to arrive as the client's own *Credential field "Username" must not
 * be blank.* — true of a value that is absent, and false of one an application
 * has plainly written down. An operator told a key is missing goes looking for
 * a missing one.
 *
 * The value is asserted absent from the message as well. Three of the keys
 * this factory serves hold credentials, so the rule is that only the type is
 * ever named; `12345` is not a credential, but it is what a credential would
 * be standing in for here.
 */
it('refuses a credential set to something that is not a string, naming its type', function (): void {
    vposConfig(['ameriabank-vpos.username' => 12345]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.username must be a string, and the configured value is of type int. It is not '
        .'missing — it is set to the wrong type, and this package refuses it rather than casting it, because a '
        .'cast would turn a misconfigured value into a silently different one. Neither the value nor any part '
        .'of it is repeated here, because these keys can hold credentials. Correct the type in '
        .'config/ameriabank-vpos.php, or in the environment variable that key reads.',
    );

    $refusal = refusalFor(fn (): Vpos => app(Vpos::class));

    expect($refusal)->not->toContain('12345')
        ->and($refusal)->not->toContain('must not be blank');
});

/*
 * ---------------------------------------------------------------------------
 * The attempt budget, refused on this side of the bridge.
 *
 * The client bounds it at 1..5 and raises ValidationException from
 * HttpTransport's constructor. That refusal names a number and nothing else,
 * because the client has no idea where the number came from. The provider now
 * refuses the value at the `maxAttempts:` argument position of
 * `new Vpos(...)`, which PHP evaluates before entering the constructor — so no
 * Vpos and no HttpTransport exists when the refusal is raised, and the
 * client's own exception is not merely pre-empted but unreachable for this
 * cause.
 * ---------------------------------------------------------------------------
 */

/**
 * The two ends of the accepted range, asserted from the inside.
 *
 * Both bounds are pinned from both sides, because the check and the message
 * read the same two named locals: a mutant that moves `$lowestBudget` to 2
 * makes this dataset red, and a mutant that moves it to 0 makes the refusal
 * dataset below red. Neither alone would catch both directions.
 */
dataset('accepted attempt budgets', [
    'the lowest the client accepts' => [1],
    'the highest the client accepts' => [5],
]);

/**
 * The first value outside the range on each side, and one well past it.
 */
dataset('refused attempt budgets', [
    'one below the lowest' => [0],
    'one above the highest' => [6],
    'well above the highest' => [9],
]);

it('accepts an attempt budget at either end of the range', function (int $configured): void {
    vposConfig(['ameriabank-vpos.max_attempts' => $configured]);

    expect(app(Vpos::class))->toBeInstanceOf(Vpos::class);
})->with('accepted attempt budgets');

it('refuses an attempt budget outside the range, naming the key and the variable', function (int $configured): void {
    vposConfig(['ameriabank-vpos.max_attempts' => $configured]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) must be an integer between 1 and 5, and '
        .'the configured value is outside that range. The value itself is not repeated here. This is the total '
        .'number of attempts a retryable operation gets; which operations may be retried at all is fixed by the '
        .'client and is not configurable.',
    );
})->with('refused attempt budgets');

it('refuses an attempt budget that is not an integer, naming its type', function (): void {
    vposConfig(['ameriabank-vpos.max_attempts' => '3']);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) must be an integer between 1 and 5, and '
        .'the configured value is of type string, which this package refuses rather than casting, because a '
        .'cast would run an attempt budget nobody configured. The value itself is not repeated here. This is '
        .'the total number of attempts a retryable operation gets; which operations may be retried at all is '
        .'fixed by the client and is not configurable.',
    );

    expect(refusalFor(fn (): Vpos => app(Vpos::class)))->not->toContain('got 0');
});

/*
 * The acceptance criterion in its own test: the client's ValidationException is
 * never raised for a budget this package refuses.
 *
 * Three assertions carry it, and none of them is a `not`:
 *
 * - the throwable is this package's ConfigurationException, which extends
 *   LogicException and is unrelated to the client's ValidationException, so
 *   being one is being not the other;
 * - it has **no previous**. Had this package caught the client's refusal and
 *   rewritten it, the cause would be there — every factory on this class that
 *   wraps another exception chains it, and this one is handed no exception to
 *   chain because none was ever raised;
 * - the client's own message for this very value is absent from the refusal.
 *   Built from the client's factory rather than quoted, so a reworded upstream
 *   message is still the thing being excluded.
 *
 * Together they say the transport was never constructed with this value: the
 * only thing that range-checks it upstream is `HttpTransport::__construct`,
 * reachable only through `Vpos::__construct`, and reaching it with a value
 * outside 1..5 raises the client's exception by construction.
 */
it('refuses an out-of-range budget itself, so the client never raises its own', function (): void {
    $configured = 9;

    vposConfig(['ameriabank-vpos.max_attempts' => $configured]);

    try {
        app(Vpos::class);
    } catch (Throwable $failure) {
        expect($failure)->toBeInstanceOf(ConfigurationException::class)
            ->and($failure->getPrevious())->toBeNull()
            ->and($failure->getMessage())
            ->not->toContain(ValidationException::maxAttemptsOutOfRange($configured)->getMessage());

        return;
    }

    throw new RuntimeException(sprintf(
        'The provider built a client with ameriabank-vpos.max_attempts set to %d, which the client bounds at '
        .'1..5. Either the bridge stopped checking the budget, or it accepted a value the client will refuse '
        .'later with a message naming no configuration key.',
        $configured,
    ));
});

it('gives the client a null logger while logging is off', function (): void {
    expect(ClientInternals::loggerOf(app(Vpos::class)))->toBeInstanceOf(NullLogger::class);
});

it('leaves logging off for anything that is not exactly true', function (): void {
    vposConfig(['ameriabank-vpos.logging.enabled' => 1]);

    expect(ClientInternals::loggerOf(app(Vpos::class)))->toBeInstanceOf(NullLogger::class);
});

it('gives the client the configured channel once logging is on', function (): void {
    vposConfig([
        'ameriabank-vpos.logging.enabled' => true,
        'ameriabank-vpos.logging.channel' => 'vpos_probe',
    ]);

    $logger = ClientInternals::loggerOf(app(Vpos::class));

    expect($logger)->toBe(Log::channel('vpos_probe'))
        ->and($logger)->not->toBeInstanceOf(NullLogger::class);
});

it('gives the client the default channel when logging is on and none is named', function (): void {
    vposConfig(['ameriabank-vpos.logging.enabled' => true]);

    $logger = ClientInternals::loggerOf(app(Vpos::class));

    expect($logger)->toBe(Log::channel())
        ->and($logger)->not->toBeInstanceOf(NullLogger::class)
        ->and($logger)->not->toBe(Log::channel('vpos_probe'));
});
