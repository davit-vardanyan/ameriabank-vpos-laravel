<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ExceptionFactories;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Support\Facades\Route;

/*
 * A refusal this package raises carries no configured value in its own frame.
 *
 * ## The defect, and why nothing here saw it
 *
 * A factory constructs its exception inside its own body, so **the factory's
 * call is frame 0 of the trace that exception carries**, and a frame records
 * the arguments its function was called with. Laravel builds its default log
 * formatter with stack traces enabled, so a reported exception writes
 * `getTraceAsString()` — arguments and all — into `storage/logs/laravel.log`.
 *
 * `notAString()` took the configured value. It kept it out of the *message*,
 * with a docblock explaining why, and handed it to the trace instead: a
 * password set to an array reached the log verbatim. The class's own
 * `__serialize()` narrows the trace and drops `args`, which covers an exception
 * that is queued and does nothing for one that is logged, because
 * `__serialize()` never runs on that path.
 *
 * The suite could not have caught it. `ConfigurationExceptionTest`'s trace
 * assertions key on `class`, `type` and `function` and deliberately not on
 * `args`, because an args-based *absence* assertion is vacuous under an INI
 * that suppresses arguments. Sound for the serialization tests it was written
 * for — but nothing anywhere looked at a **live** exception's arguments, and
 * the leak lived only there.
 *
 * ## What this file asserts, and why it is phrased positively
 *
 * Not "the value is absent from the arguments". That is an absence assertion,
 * and this repository has three separate reasons not to write one here: no
 * mutant can observe it, since mutation removes and inverts code and never adds
 * it; its needle goes stale silently, as two assertions in this suite did the
 * day the sentence they named was reworded; and under
 * `zend.exception_ignore_args=On` there are no arguments to look in, so it
 * passes by having nothing to read.
 *
 * Instead: **frame 0's arguments are asserted to be exactly what they should
 * be** — a configuration key and a type name, a container key and a type name,
 * the bounds a check compared against, a chained cause. A value appearing is a
 * failure of an equality, which fails whether or not anybody predicted the
 * shape it would appear in, and which a mutant can kill.
 *
 * ## Why it cannot pass vacuously
 *
 * `zend.exception_ignore_args` is asserted to be off, in its own test and again
 * per subject through the presence of the `args` key. `phpunit.xml.dist` pins
 * it to 0 for precisely this reason, and GitHub's runners load
 * `php.ini-production`, which turns it on. Without that pin every assertion
 * below would be comparing an empty list against an empty list, and the file
 * would be green while reading nothing at all.
 *
 * ## The subject list
 *
 * Derived: `ExceptionFactories::all()` walks composer.json's PSR-4 map and
 * reflects the public static factories off every exception class this package
 * ships. The table below is keyed by that derived name, so a factory with no
 * row fails by name and a row nothing discovers is dead — the mechanism
 * `CheckCommandExceptionCoverageTest` uses against the client package's
 * exception directory, pointed at this package's own.
 *
 * Each row provokes its factory **through the real code path**, from
 * configuration or from a container binding, so what is inspected is the
 * exception a merchant would actually get rather than one this file built.
 *
 * One row per factory, not per throw site. Two throw sites may call one factory
 * — `unknownEnvironment()` has one in the service provider and one in
 * `vpos:check`, each raised from its own `Environment::tryFrom()` — and the
 * parameter types they must satisfy are the factory's, which
 * `tests/Arch/ExceptionFactorySignatureTest.php` holds for every factory at
 * once. That is the structural half of this claim; this is the behavioural
 * half.
 */

/**
 * An obviously-fake stand-in for the thing that must not reach a log.
 *
 * The provocations below configure it where a real deployment holds a
 * credential. It is not a credential and not shaped like one on purpose: if it
 * ever appears in a failure diff, it should be unmistakable that it came from
 * here.
 */
function traceCanary(): string
{
    return 'CANARY-NOT-A-REAL-CREDENTIAL-8d31c7';
}

/**
 * How to make each factory raise for real, and what its own frame may carry.
 *
 * `args` is a closure over the caught refusal rather than a literal list,
 * because two of these factories chain a cause and the cause is an object no
 * literal can name. Reading it back off the exception makes the expectation
 * exact — object identity, not a class name that a leaked object would also
 * match.
 *
 * @return array<string, array{provoke: Closure(): void, args: Closure(ConfigurationException): list<mixed>}>
 */
function configurationRefusalProvocations(): array
{
    return [
        /*
         * A back_url that is not a string at all. It is read as blank, so the
         * factory that takes nothing is the one that runs — and taking nothing
         * is what makes its frame incapable of carrying anything.
         */
        ConfigurationException::class.'::blankBackUrl' => [
            'provoke' => static function (): void {
                vposConfig(['ameriabank-vpos.back_url' => ['token' => traceCanary()]]);

                app(BackUrlResolver::class)->resolve();
            },
            'args' => static fn (ConfigurationException $failure): array => [],
        ],

        /*
         * A route name nobody registered. The value is a route name, never a
         * credential, and the message names it deliberately — the whole
         * difficulty of this mistake is that a typo and a route name look
         * identical. So its presence here is the correct answer, and the
         * assertion pins it rather than forbidding it.
         *
         * The second argument is the framework's own exception, chained. Read
         * back off the refusal, so the expectation is that exact instance.
         */
        ConfigurationException::class.'::unresolvableBackUrlRoute' => [
            'provoke' => static function (): void {
                vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.bakc']);

                app(BackUrlResolver::class)->resolve();
            },
            'args' => static fn (ConfigurationException $failure): array => [
                'checkout.vpos.bakc',
                $failure->getPrevious(),
            ],
        ],

        /*
         * A route that is registered and needs a parameter. Same shape as
         * above and a different framework exception: UrlGenerationException
         * extends Exception directly, so the clause that catches the
         * unregistered case has never seen it.
         */
        ConfigurationException::class.'::parameterisedBackUrlRoute' => [
            'provoke' => static function (): void {
                Route::get('/checkout/{order}/vpos/back', static fn (): string => 'ok')
                    ->name('checkout.vpos.parameterised');

                vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.parameterised']);

                app(BackUrlResolver::class)->resolve();
            },
            'args' => static fn (ConfigurationException $failure): array => [
                'checkout.vpos.parameterised',
                $failure->getPrevious(),
            ],
        ],

        /*
         * An environment the client does not know. The value is named in the
         * message on purpose — an operator cannot correct a spelling they are
         * not shown — and `environment` is not one of the keys that can hold a
         * credential.
         */
        ConfigurationException::class.'::unknownEnvironment' => [
            'provoke' => static function (): void {
                vposConfig(['ameriabank-vpos.environment' => 'staging']);

                app(Vpos::class);
            },
            'args' => static fn (ConfigurationException $failure): array => ['staging'],
        ],

        /*
         * **The Blocker's own factory.** A password holding an array, which is
         * the shape a YAML- or JSON-sourced secret arrives in. The canary is
         * inside that array, so a factory taking the value puts the array —
         * canary and all — in this frame, and the equality below fails.
         *
         * `password` is the only wrong-typed key in the fixture, so which key
         * this reports is decided by the fixture and not by the order the
         * provider happens to read them in.
         */
        ConfigurationException::class.'::notAString' => [
            'provoke' => static function (): void {
                vposConfig(['ameriabank-vpos.password' => ['token' => traceCanary()]]);

                app(Vpos::class);
            },
            'args' => static fn (ConfigurationException $failure): array => ['password', 'array'],
        ],

        /*
         * An attempt budget configured as text. Not a credential, and the
         * frame is asserted all the same: consistency is the point, since a
         * factory shaped to accept the value is where the next credential gets
         * routed.
         *
         * The two bounds are the caller's, passed so that the check and the
         * sentence explaining it read the same numbers.
         */
        ConfigurationException::class.'::invalidMaxAttempts' => [
            'provoke' => static function (): void {
                vposConfig(['ameriabank-vpos.max_attempts' => traceCanary()]);

                app(Vpos::class);
            },
            'args' => static fn (ConfigurationException $failure): array => ['string', 1, 5],
        ],

        /*
         * The package-scoped container key bound to something that is not a
         * PSR-18 client. The bound object holds the canary, which is what a
         * real one would hold: a client wrapping an API token, or a
         * misconfigured factory's return.
         *
         * Nothing bound to a container key is safe to accept, let alone print.
         */
        ConfigurationException::class.'::httpClientNotPsr18' => [
            'provoke' => static function (): void {
                vposConfig();

                app()->instance(
                    AmeriabankVposServiceProvider::HTTP_CLIENT_KEY,
                    (object) ['token' => traceCanary()],
                );

                app(Vpos::class);
            },
            'args' => static fn (ConfigurationException $failure): array => [
                AmeriabankVposServiceProvider::HTTP_CLIENT_KEY,
                'stdClass',
            ],
        ],

        /*
         * A callback resolved where no callback ever arrived. Its only argument
         * is the client's own refusal, chained so that the parameter it names
         * is not lost.
         */
        ConfigurationException::class.'::callbackOutsideRequest' => [
            'provoke' => static function (): void {
                vposConfig();

                app(VposCallback::class);
            },
            'args' => static fn (ConfigurationException $failure): array => [$failure->getPrevious()],
        ],
    ];
}

/**
 * The frame that raised $failure, which is the factory's own call.
 *
 * A refusal rather than a skip when nothing was raised: a provocation that
 * stopped provoking would otherwise leave this file asserting against an empty
 * list and reporting green, which is the direction that hides the leak.
 *
 * @param  Closure(): void  $provoke
 * @return array{failure: ConfigurationException, frame: array<array-key, mixed>}
 */
function raisingFrameOf(Closure $provoke): array
{
    try {
        $provoke();
    } catch (ConfigurationException $failure) {
        $frame = $failure->getTrace()[0] ?? null;

        if (! is_array($frame)) {
            throw new RuntimeException(
                'The refusal carries no frame 0, so there is nothing to read the raising call\'s arguments '
                .'from and every assertion about them would be about an empty list.',
                0,
                $failure,
            );
        }

        return ['failure' => $failure, 'frame' => $frame];
    }

    throw new RuntimeException(
        'The provocation raised no ConfigurationException, so this guard inspected the trace of nothing. A row '
        .'that has stopped provoking its factory passes every assertion below without reading a single frame.',
    );
}

dataset('configuration refusals', fn (): array => array_keys(configurationRefusalProvocations()));

/*
 * The pin the rest of this file rests on, asserted rather than assumed.
 *
 * `zend.exception_ignore_args=On` removes `args` from every frame. Under it,
 * every argument list below is empty, every equality compares two empty lists,
 * and the file is green while reading nothing — which is exactly the state a
 * leak would survive. GitHub's runners load php.ini-production, which sets it
 * on, so this is the ambient default in CI and not a hypothetical.
 *
 * `phpunit.xml.dist` pins it to 0 in a `<php><ini>` block, which PHPUnit
 * applies after startup and which therefore outranks a `-d` flag on the command
 * line. This is what makes losing that pin loud instead of silent.
 *
 * The companion directive, `zend.exception_string_param_max_len`, is not
 * asserted here and the omission is deliberate: it truncates strings in the
 * *rendered* `getTraceAsString()` and does not touch `getTrace()`, which is
 * what this file reads. Reading the array rather than the rendering is why a
 * long credential could not be hidden from these assertions by truncation.
 */
it('runs with exception arguments recorded, without which every guard here is vacuous', function (): void {
    expect(ini_get('zend.exception_ignore_args'))->toBe(
        '0',
        'Exception frames are not recording their arguments, so every assertion in this file compares an empty '
        .'list against an empty list and cannot see a configured value in a trace even if one is there. '
        .'phpunit.xml.dist pins zend.exception_ignore_args to 0 for this reason; restore it rather than '
        .'weakening the assertions that depend on it.',
    );
});

it('has a live provocation for every refusal this package can raise', function (): void {
    $declared = array_keys(ExceptionFactories::all());
    $covered = array_keys(configurationRefusalProvocations());
    sort($covered);

    expect($covered)->toBe($declared, sprintf(
        "This package's exception factories and the provocations in this file have diverged.\nDeclared: %s\n"
        .'Provoked: %s'."\n"
        .'A factory with no row here has never had its own trace frame read, which is where the configured '
        .'value it was handed would be — the exact defect this file was written for, and one that no message '
        .'assertion anywhere can see. Add a row that provokes it through the real code path and states what its '
        .'frame may carry. A row nothing declares is dead and should go.',
        implode(', ', $declared),
        implode(', ', $covered),
    ));
});

it('carries no configured value in the frame that raised it', function (string $factory): void {
    $row = configurationRefusalProvocations()[$factory];

    ['failure' => $failure, 'frame' => $frame] = raisingFrameOf($row['provoke']);

    // Which frame this is, before anything is said about what it holds. Frame 0
    // is the factory's own call only because the exception is constructed
    // inside it; an exception built somewhere else would put a different call
    // here and the arguments below would be a different function's.
    expect($frame['class'] ?? null)->toBe(ConfigurationException::class)
        ->and($frame['type'] ?? null)->toBe('::')
        ->and($frame['function'] ?? null)->toBe(str_replace(ConfigurationException::class.'::', '', $factory));

    // The per-subject half of the INI pin. Under php.ini-production this key is
    // absent and the equality below would compare [] with [], so its absence is
    // a refusal rather than a pass with nothing in it.
    //
    // Written as array_key_exists() rather than toHaveKey(), and that is not a
    // stylistic choice: `toHaveKey(string|int $key, mixed $value = new Any,
    // string $message = '')` takes the expected *value* second and the message
    // third, so `toHaveKey('args', $why)` asserts that args equals the sentence
    // explaining the assertion. It is the same trap as toContain() being
    // variadic, and it was written that way here first.
    expect(array_key_exists('args', $frame))->toBeTrue(
        'Frame 0 records no arguments, so this subject proves nothing about what was handed to the factory. '
        .'zend.exception_ignore_args has been turned on somewhere between phpunit.xml.dist and here.',
    );

    // The assertion itself, and it is an equality on purpose. "The value is not
    // in the arguments" would be an absence assertion: unkillable by any
    // mutant, quietly stale the day its needle stops existing, and satisfied by
    // an empty list. This says what the arguments ARE — a key, a type name, a
    // bound, a chained cause — so a value appearing fails it whatever shape it
    // appears in, and nobody has to have predicted that shape.
    expect($frame['args'])->toBe(($row['args'])($failure), sprintf(
        'The frame that raised %s carries arguments this package did not intend it to. A factory builds its '
        .'exception in its own body, so this frame is the factory\'s own call, getTraceAsString() renders its '
        .'arguments, and Laravel\'s default log formatter writes that rendering to the application log. '
        .'Resolve the value to a type name with get_debug_type() at the throw site and hand the factory the '
        .'name; do not filter the trace afterwards, and do not rely on __serialize(), which does not run for an '
        .'exception that is only logged.',
        $factory,
    ));
})->with('configuration refusals');
