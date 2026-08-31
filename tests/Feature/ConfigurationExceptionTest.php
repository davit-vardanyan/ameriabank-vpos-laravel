<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;

/**
 * A real serialization round trip, back as the type it went in as.
 */
function roundTrip(ConfigurationException $failure): ConfigurationException
{
    $restored = unserialize(serialize($failure));

    if (! $restored instanceof ConfigurationException) {
        throw new RuntimeException('A serialized ConfigurationException did not come back as one.');
    }

    return $restored;
}

/**
 * The trace `__serialize()` actually published.
 *
 * `__serialize()` returns `array<string, mixed>`, so its `trace` entry is a
 * `mixed` and nothing can be asserted about it at level 10 without saying what
 * it is first. Saying so here — and failing loudly when it is not — keeps the
 * expectations below statements about the payload rather than casts over it.
 *
 * @return list<array<array-key, mixed>>
 */
function publishedTrace(ConfigurationException $failure): array
{
    $published = $failure->__serialize()['trace'] ?? null;

    if (! is_array($published)) {
        throw new RuntimeException('__serialize() published no trace.');
    }

    $frames = [];

    foreach ($published as $frame) {
        if (! is_array($frame)) {
            throw new RuntimeException('__serialize() published a trace frame that is not an array.');
        }

        $frames[] = $frame;
    }

    return $frames;
}

/**
 * The exception's own trace, narrowed to the five keys that name code.
 *
 * Stated here rather than read back out of `__serialize()`, so that the
 * expectations below compare the published payload against the trace it came
 * from rather than against itself.
 *
 * @return list<array<array-key, mixed>>
 */
function narrowedTrace(ConfigurationException $failure): array
{
    return array_map(
        static fn (array $frame): array => array_intersect_key(
            $frame,
            array_flip(['file', 'line', 'function', 'class', 'type']),
        ),
        $failure->getTrace(),
    );
}

/**
 * Restores a payload the way a queue worker handling a failed payment would,
 * and hands back the two things it is the source of truth for.
 *
 * Named distinctively, and deliberately so: the guard below asserts that this
 * name is *absent* from the restored exception's trace. A restore site is
 * ordinary application code — a job handler, a retry wrapper, a log viewer —
 * and an exception describing where a misconfiguration was found has no
 * business describing it instead.
 *
 * The second parameter stands for whatever such a worker is really called
 * with. It is an obviously-fake marker and never a credential; it is here
 * because a frame records the arguments passed to its function, so a
 * parameter in this position is precisely what a restore-site trace publishes.
 *
 * `name` and `marker` are returned rather than written down again at the
 * assertion, and that is the point of the return type. Both are needles in an
 * absence assertion, and a needle that has stopped matching anything makes such
 * an assertion pass against every possible trace, the leaking one included —
 * silently, and with no mutant able to observe it, because mutation cannot
 * cover a negative. A quoted copy of this function's name stops matching the
 * moment an editor renames the function, which touches the declaration and the
 * call and not the string; a quoted copy of the marker stops matching the
 * moment the call site is passed a different one. `__FUNCTION__` is the name
 * this frame will actually carry, and `$cardNumber` is the value that was
 * actually passed, so neither needle can drift away from its subject.
 *
 * @return array{name: string, marker: string, restored: ConfigurationException}
 */
function restoreInsideAFailedPaymentWorker(string $payload, string $cardNumber): array
{
    if ($cardNumber === '') {
        throw new RuntimeException('A failed-payment worker is called with the card that failed.');
    }

    $restored = unserialize($payload, ['allowed_classes' => [ConfigurationException::class]]);

    if (! $restored instanceof ConfigurationException) {
        throw new RuntimeException('A serialized ConfigurationException did not come back as one.');
    }

    return ['name' => __FUNCTION__, 'marker' => $cardNumber, 'restored' => $restored];
}

/**
 * Every frame of a throwable's trace, named `Class::function` or `function`.
 *
 * Keyed on `class`, `type` and `function` rather than on `args`, and that
 * choice is the whole point. Those three are recorded whatever
 * `zend.exception_ignore_args` is set to, while under php.ini-production a
 * frame carries no `args` at all — so an assertion phrased as "the secret does
 * not appear in the trace" would pass on a production INI because there was
 * nothing there to look at. Green in the direction that hides the leak, which
 * is the failure mode phpunit.xml.dist pins those two directives to prevent.
 * A frame's identity has no such vacuity.
 *
 * @return list<string>
 */
function frameNames(Throwable $failure): array
{
    $names = [];

    foreach ($failure->getTrace() as $frame) {
        $names[] = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
    }

    return $names;
}

it('is catchable as anything either package can raise', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    expect($failure)->toBeInstanceOf(VposExceptionInterface::class)
        ->and($failure)->toBeInstanceOf(LogicException::class)
        ->and($failure->getCode())->toBe(0)
        ->and($failure->getPrevious())->toBeNull();
});

it('can only be built through one of its named factories', function (): void {
    expect((new ReflectionClass(ConfigurationException::class))->getConstructor()?->isPrivate())->toBeTrue();
});

it('reports nothing about a dropped chain until it has been through one', function (): void {
    expect(ConfigurationException::blankBackUrl()->chainDropped())->toBeNull()
        ->and(ConfigurationException::unresolvableBackUrlRoute('x', new RuntimeException('y'))->chainDropped())
        ->toBeNull();
});

it('serializes only what it chose to publish', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    expect($failure->__serialize())->toBe([
        'message' => $failure->getMessage(),
        'file' => $failure->getFile(),
        'line' => $failure->getLine(),
        'trace' => narrowedTrace($failure),
        'chainDropped' => false,
    ]);
});

it('publishes a trace that names code and carries no values', function (): void {
    $published = publishedTrace(ConfigurationException::blankBackUrl());

    expect($published)->not->toBeEmpty()
        ->and(unserialize(serialize($published)))->toBe($published);

    foreach ($published as $frame) {
        expect(array_diff(array_keys($frame), ['file', 'line', 'function', 'class', 'type']))->toBe([]);
    }
});

it('records that it had a cause, since the cause itself cannot travel', function (): void {
    $failure = ConfigurationException::unresolvableBackUrlRoute('checkout.back', new RuntimeException('no route'));

    expect($failure->__serialize())->toBe([
        'message' => $failure->getMessage(),
        'file' => $failure->getFile(),
        'line' => $failure->getLine(),
        'trace' => narrowedTrace($failure),
        'chainDropped' => true,
    ]);
});

it('comes back from a round trip still pointing at where the mistake was found', function (): void {
    $failure = ConfigurationException::blankBackUrl();
    $restored = roundTrip($failure);

    expect($restored->getMessage())->toBe($failure->getMessage())
        ->and($restored->getFile())->toBe($failure->getFile())
        ->and($restored->getLine())->toBe($failure->getLine())
        ->and($restored->getFile())->not->toBe(__FILE__)
        ->and($restored->getPrevious())->toBeNull()
        ->and($restored->chainDropped())->toBeFalse();
});

it('says so when the round trip dropped a cause', function (): void {
    $restored = roundTrip(
        ConfigurationException::unresolvableBackUrlRoute('checkout.back', new RuntimeException('no route')),
    );

    expect($restored->getPrevious())->toBeNull()
        ->and($restored->chainDropped())->toBeTrue()
        ->and($restored->getMessage())->toContain('checkout.back');
});

it('comes back naming where the mistake was raised, never who restored it', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    ['name' => $restoreFrame, 'marker' => $marker, 'restored' => $restored] =
        restoreInsideAFailedPaymentWorker(serialize($failure), 'CARD-NOT-A-REAL-CREDENTIAL');

    $names = frameNames($restored);

    // The positive half. Without it, "the trace names nothing of the restore
    // site" would also be satisfied by a trace that names nothing at all, and
    // the guard would report green on the very state — an empty trace — that
    // the published entry exists to replace.
    expect($names)->not->toBeEmpty()
        ->and($names)->toContain(ConfigurationException::class.'::blankBackUrl');

    // The negative half, and the assertion the suite was missing. Collected
    // into a list and compared with toBe() rather than written as
    // not->toContain(): toContain() is variadic, so a second argument to it is
    // another needle and not a failure message.
    //
    // The restore frame's name comes back from the restore site rather than
    // being quoted here. Written as a literal it was a second copy of the
    // function's name, and a rename would have left it matching nothing at all
    // — which is not a guard that fails, it is a guard that passes against
    // every trace including the leaking one. `unserialize` is PHP's own and
    // cannot be renamed, so it stays written.
    expect(array_values(array_intersect($names, [$restoreFrame, 'unserialize'])))
        ->toBe([], sprintf('The restored exception names the frame that restored it (%s).', $restoreFrame));

    // Secondary, and INI-dependent by nature: it can only see a leak while
    // `zend.exception_ignore_args=0` puts arguments in the frames at all, which
    // is why it is not the assertion above. It is here because this marker is
    // the shape of the value that actually escaped, not because it could stand
    // on its own. Read frame by frame rather than by serializing the trace: an
    // unfiltered trace holds closures, and serializing one raises instead of
    // asserting, which is a failure without a finding.
    $stringArguments = [];

    foreach ($restored->getTrace() as $frame) {
        foreach ($frame['args'] ?? [] as $argument) {
            if (is_string($argument)) {
                $stringArguments[] = $argument;
            }
        }
    }

    // The marker is the value the restore site was actually called with, handed
    // back by the call rather than transcribed from it, for the same reason as
    // the frame name above: a transcribed marker stops matching the day the
    // call site is passed a different one, and an absence assertion whose
    // needle matches nothing is green by construction.
    expect(array_values(array_intersect($stringArguments, [$marker])))
        ->toBe([], 'A restored frame still carries an argument the restore site was called with.');
});

it('degrades rather than throwing when the restored payload is unusable', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    $failure->__unserialize([
        'message' => 42,
        'file' => [],
        'line' => '17',
        'trace' => ['a frame is an array, and this is not', ['function' => 'pay', 'args' => ['dropped']]],
        'chainDropped' => 'yes',
    ]);

    expect($failure->getMessage())->toBe('')
        ->and($failure->getFile())->toBe('')
        ->and($failure->getLine())->toBe(0)
        ->and($failure->getTrace())->toBe([['function' => 'pay']])
        ->and($failure->chainDropped())->toBeFalse();
});

it('drops a restored frame key whose value is not the type that key holds', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    // The first five keys are ones the filter permits by name, so narrowing by
    // name alone keeps them whole; only the last two of those hold what their
    // key is read back as. `args` is the other half of the same statement: a
    // payload is free to claim any type for it, and a string is one the type
    // check would wave through, so it is only the *name* that can drop it. The
    // two filters have to be independent, and a frame carrying both cases is
    // what says so.
    $failure->__unserialize(['trace' => [[
        'file' => ['nested', 'array'],
        'line' => 'not-an-int',
        'function' => new stdClass,
        'class' => ConfigurationException::class,
        'type' => '::',
        'args' => 'a string, so nothing but the key filter can drop it',
    ]]]);

    expect($failure->getTrace())->toBe([['class' => ConfigurationException::class, 'type' => '::']]);

    // The symptom rather than a restatement of the filter. getTraceAsString()
    // reads the frames back positionally and raises "File name is not a string"
    // and "Line is not an int" on anything else — warnings that phpunit.xml.dist
    // fails the run on, and that Laravel's error handler turns into an
    // ErrorException inside whichever worker read the payload. Calling it here
    // is the assertion; what it returns is only the receipt.
    expect($failure->getTraceAsString())->toContain(ConfigurationException::class.'::');
});

it('keeps nothing from a trace that is not one', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    $failure->__unserialize(['trace' => 'this is a string, not a trace']);

    expect($failure->getTrace())->toBe([]);
});

it('degrades rather than throwing when the restored payload is empty', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    $failure->__unserialize([]);

    expect($failure->getMessage())->toBe('')
        ->and($failure->getFile())->toBe('')
        ->and($failure->getLine())->toBe(0)
        ->and($failure->getTrace())->toBe([])
        ->and($failure->chainDropped())->toBeFalse();
});
