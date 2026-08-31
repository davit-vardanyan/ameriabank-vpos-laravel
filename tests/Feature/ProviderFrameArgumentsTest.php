<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Laravel\Commands\CheckCommand;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\BrokenOutput;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;

/*
 * A service provider hands nothing out that reaches the credentials.
 *
 * `ConfigurationExceptionTraceTest` holds frame 0 — the factory's own call — for
 * every refusal this package can raise. This file holds the frames underneath
 * it, which is where the same defect reappeared in a different shape and where
 * nothing was looking.
 *
 * ## What went wrong, measured
 *
 * `makeCallback()` took the container. It read no configuration at all, so when
 * every other method on the credential path was narrowed to reach the container
 * through `$this`, it was left alone and the provider's docblock recorded the
 * result as an absolute — "nothing below takes the container or the
 * configuration repository as an argument" — while one method below it did.
 * Resolving a callback outside a request produced:
 *
 *     0 ConfigurationException::callbackOutsideRequest(ValidationException)
 *     1 AmeriabankVposServiceProvider::makeCallback(Illuminate\Foundation\Application)
 *     2 AmeriabankVposServiceProvider::{closure}(Illuminate\Foundation\Application, array)
 *
 * Frame 1 is a frame this package declares, and the walk from its argument to
 * the live password is one hop — `$app->make('config')->get(…)`. It is also a
 * refusal that reaches an error reporter: `makeVpos()`'s refusal carries clean
 * frames, but a controller type-hinting `VposCallback` lets this one out to the
 * application's handler, which logs `getTraceAsString()` — arguments and all —
 * and forwards it to whatever reporter the application runs.
 *
 * This paragraph used to call it *the* one such refusal, on the ground that
 * `vpos:check` catches `Throwable` so nothing escapes it. That was measured and
 * is false — see the section on the command below — and claim 4 exists because
 * of it.
 *
 * **Reading configuration was never the test.** Handing out something that
 * reaches it is, and the container reaches everything.
 *
 * ## Four claims, held four ways
 *
 * 1. **The structural half, and the one that would have caught it.** No method
 *    declared on a service provider this package ships takes the container or
 *    the configuration repository. Derived by reflection over the methods whose
 *    declaring class is the provider, so a private helper added tomorrow is a
 *    subject the day it is written.
 * 2. **The behavioural half.** The refusal is provoked through the real code
 *    path and frame 1's arguments are read back and asserted empty. A structural
 *    rule about parameter lists is a claim about what a trace will hold; this is
 *    the trace.
 * 3. **The residue, asserted rather than denied.** The frame under those is the
 *    binding closure, and it carries the container whatever the closure declares
 *    — the container passes itself to everything it builds from. That frame is
 *    not something a parameter list can remove, the README now tells merchants
 *    it is there, and so it is pinned as present rather than described as
 *    absent. A future "we cleaned that up" fails here.
 * 4. **The other escape, measured rather than asserted.** `vpos:check` ends in
 *    `catch (Throwable)`, and several statements — two of them console writes —
 *    run before its `try` opens. A failure in one of those escapes the command
 *    entirely and reaches the console kernel, which reports it — so it is a
 *    second refusal that reaches a reporter, and it was described in prose here
 *    as impossible until it was measured. It is now provoked and its trace read,
 *    in both directions: the frame this package declares carries no arguments,
 *    and the framework frames that do carry the container are pinned as present.
 *
 * ## Why the first claim is scoped to service providers rather than to `src/`
 *
 * The underlying rule is about any method that can appear in the trace of a
 * refusal that escapes, and that is not a property reflection can read. What can
 * be read is where the container is *ambient*, and that is exactly a service
 * provider: it is constructed with the application and every method on it can
 * reach the container through `$this`, so a parameter is never the only way to
 * get one and the rule can be absolute with no exception to state.
 *
 * **Widening it to `src/` was measured before being kept out, and the counts are
 * what decided it.** Over every class this package's own PSR-4 map reaches, the
 * clause below inspects 52 parameter positions and reports 45 of them. They fall
 * into three groups, and only the first is cheap to answer:
 *
 * - **42 are builtin-typed and cannot hold an object at all** — 26 `string`,
 *   6 `int`, 6 `bool`, 2 `array`, and 2 unions of builtins. They are reported
 *   only because `declaredTypeNames()` yields the empty list for a builtin and
 *   the clause reads an empty list as "names no class". In this scope that
 *   over-strictness is free, because no method a provider here declares takes a
 *   builtin at all. Widened it is not free: it has to become a judgement about
 *   which builtin keywords admit an object, and that judgement is a list of
 *   keywords written into this file — the thing the section below refuses to
 *   write for class names, for the same reason.
 * - **Two are the sanctioned remedy.** `ConfigReader::__construct(private
 *   Repository $config)` is what took the repository out of
 *   `ConfigReader::string()`'s frame, and `BackUrlResolver`'s constructor is the
 *   same shape; forbidding them would forbid the fix. This group is soluble
 *   without naming either: both bodies are empty, an empty body evaluates
 *   nothing and so raises nothing beneath itself, and the only exception such a
 *   constructor can raise at all is a binding failure — whose recorded argument
 *   is, by construction, not an instance of the type it failed to bind to.
 *   Measured: constructing `ConfigReader` with a string raises `TypeError` with
 *   frame 0 `ConfigReader->__construct(string)`, and a string is not the
 *   repository. A derived exemption is therefore available here.
 * - **One is neither, and it is the group that settles it.**
 *   `CheckCommand::absentOrMistyped(mixed $value)` is handed a configured value,
 *   the live password among them, and `mixed` is a type the clause reports on
 *   purpose. The only property that makes it safe is that its callers sit inside
 *   the `try` whose `catch (Throwable)` ends that method — a claim about control
 *   flow rather than about a signature, and the exact shape of prose absolute
 *   this file has already had to delete once (see below). **A rule whose last
 *   exemption rests on the sentence that was removed is not a rule to widen
 *   into.**
 *
 * There is also less left to catch than the widening suggests. The non-provider
 * class where a frame is most exposed is the exception factory — its own call is
 * frame 0 of every refusal it builds — and
 * `tests/Arch/ExceptionFactorySignatureTest.php` already holds it with a
 * strictly stronger rule: an allowlist of `string`, `int`, `float`, `bool` and
 * `Throwable`, refusing `mixed`, `object`, `array`, a union, an intersection, an
 * arbitrary class and no type at all. Widening this one would duplicate that
 * guard more weakly.
 *
 * A rule that has to carve out its own remedy is the wrong rule. This one has no
 * carve-out, and the boundary is recorded here rather than left to be
 * rediscovered.
 *
 * ## `vpos:check` no longer needs a carve-out, and what is true in its place
 *
 * This section used to give the command as the first of the two shapes a
 * widening would wrongly report, on two grounds: that `handle()` takes the
 * container because the container calls it, and that its `catch (Throwable)`
 * clause means no exception raised beneath it escapes to a reporter at all.
 * **The first was true and is now moot. The second was false**, and stating it
 * as an absolute is the defect this file was otherwise written to prevent: the
 * method reads its option and announces the mode it is running in before that
 * `try` opens — two of those statements write to the console — and a failure in
 * any of them escapes with `handle()`'s own frame attached.
 *
 * Both halves have moved. `handle()` now declares no parameters — the two values
 * it used are resolved inside the `try` at their point of use. The escape has
 * not gone away and is not claimed to have. Measured, by running the command
 * with an output whose `doWrite()` raises, which is the shape a stream that has
 * gone away produces:
 *
 *      6 Illuminate\Console\Command->line(string)
 *      7 CheckCommand->handle()
 *      8 Illuminate\Container\BoundMethod::{closure}()
 *      9 Illuminate\Container\Util::unwrapIfClosure(Closure)
 *     10 Illuminate\Container\BoundMethod::callBoundMethod(Illuminate\Foundation\Application, array, Closure)
 *     11 Illuminate\Container\BoundMethod::call(Illuminate\Foundation\Application, array, array, null)
 *
 * So, exactly and no further: the statements before the `try` can still throw;
 * what escapes carries no arguments in the frame this package declares; and the
 * frames that do carry the container are Laravel's own call into the command,
 * which is the same residue as claim 3 and outside this package's reach for the
 * same reason. The fourth expectation below holds all three rather than leaving
 * them as prose, because prose is how the false half survived.
 *
 * ## The forbidden types are asked of the container, not listed
 *
 * A list of interface names — the two container contracts, the configuration
 * contract, their concrete classes, whatever Laravel adds next — is a
 * hand-maintained subject list, and a name missing from it passes silently. So
 * the question is put to the objects instead: **a parameter type is forbidden
 * when the live application container or the live configuration repository is
 * an instance of it.** That answers correctly for every contract either object
 * implements, for the concrete classes, for a container the framework swaps in,
 * and for an interface nobody here has heard of.
 *
 * A parameter whose declared type names no class is reported for the same
 * reason: the instanceof question cannot be asked of it, so nothing here can
 * clear it. That is stated as the clause behaves rather than as it reads. It
 * catches a parameter with no type at all and one typed `mixed`, which do admit
 * the container — and it also catches every builtin, which does not. The
 * over-strictness is deliberate and costs nothing in this scope, because no
 * method a provider here declares takes a builtin; a rule with no exception to
 * remember is worth more than one that is exactly tight, and if that ever stops
 * being true it fails loudly rather than silently. It is also one of the two
 * reasons the rule is not widened to `src/`, where 42 builtin-typed parameters
 * would be reported for naming no class.
 */

/**
 * The service providers this package ships.
 *
 * Derived, and required to be non-empty: a subject list that quietly resolved to
 * nothing would report no violation whatever any provider declared.
 *
 * @return list<class-string>
 *
 * @throws JsonException when composer.json is not valid JSON
 */
function shippedProviders(): array
{
    $providers = ProductionClasses::extending(ServiceProvider::class);

    expect($providers)->not->toBeEmpty(
        'This package ships no service provider that its own PSR-4 map can reach, so this guard has no subjects '
        .'and would pass whatever any of them declared.'
    );

    return $providers;
}

/**
 * Whether $type is one the container or the configuration repository satisfies.
 *
 * Both live objects are asked, so the answer covers every contract they
 * implement and every concrete class they are, without a name being written
 * down anywhere.
 *
 * **`instanceof` against a name held in a variable does not autoload, and here
 * it does not need to.** A type that either object satisfies is necessarily
 * already loaded — the object could not have been constructed otherwise, since
 * declaring a class that implements an interface loads that interface. So the
 * one case where the missing autoload would answer falsely is the case that
 * cannot arise: a name neither object satisfies answers `false` either way.
 *
 * @param  list<object>  $reached
 */
function admitsAReachingObject(string $type, array $reached): bool
{
    foreach ($reached as $object) {
        if ($object instanceof $type) {
            return true;
        }
    }

    return false;
}

/**
 * The class names a parameter's declared type mentions, however it is composed.
 *
 * A union or an intersection is walked into rather than skipped: `Application|
 * string` admits the container exactly as `Application` does, and a guard that
 * only understood the simple form would be bypassed by a wider one.
 *
 * An untyped parameter yields the empty list, which the caller reports — the
 * absence of a type is not the absence of a hazard.
 *
 * @return list<string>
 */
function declaredTypeNames(?ReflectionType $type): array
{
    if ($type instanceof ReflectionNamedType) {
        return $type->isBuiltin() ? [] : [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $names = [];

        foreach ($type->getTypes() as $member) {
            foreach (declaredTypeNames($member) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    return [];
}

it('declares no service provider method that is handed the container or the configuration', function (): void {
    $reached = [app(), app(Repository::class)];

    // The premise, asserted before anything rests on it. If neither object
    // admits its own class, is_a() is answering nothing and every parameter
    // below would be cleared by a question that cannot fail.
    $premise = [];

    foreach ($reached as $object) {
        if (! admitsAReachingObject($object::class, $reached)) {
            $premise[] = $object::class;
        }
    }

    expect($premise)->toBe([], sprintf(
        'These objects are not recognised as instances of their own classes, so the test every parameter below '
        ."is put through cannot report anything:\n%s",
        implode("\n", $premise),
    ));

    $violations = [];

    foreach (shippedProviders() as $provider) {
        foreach ((new ReflectionClass($provider))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $provider) {
                // Inherited from Laravel's own ServiceProvider, whose
                // __construct() takes the application by design. This package
                // did not write that parameter list and cannot narrow it.
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $names = declaredTypeNames($parameter->getType());

                if ($names === []) {
                    $violations[] = sprintf(
                        '%s::%s() takes $%s with no class type, which admits the container as readily as naming it would',
                        $provider,
                        $method->getName(),
                        $parameter->getName(),
                    );

                    continue;
                }

                foreach ($names as $name) {
                    if (admitsAReachingObject($name, $reached)) {
                        $violations[] = sprintf(
                            '%s::%s() takes $%s typed %s, which the container or the configuration repository satisfies',
                            $provider,
                            $method->getName(),
                            $parameter->getName(),
                            $name,
                        );
                    }
                }
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "A service provider method is handed something that reaches the credentials:\n%s\n"
        .'Every frame a method contributes to a refusal records the arguments it was called with, and Laravel\'s '
        .'default log formatter writes that rendering into the application log and hands the exception to '
        .'whatever reporter is installed. From the container the walk to the live password is one hop. A service '
        .'provider reaches the container through $this, so no method here needs it in its parameter list: drop '
        .'the parameter and read $this->app instead.',
        implode("\n", $violations),
    ));
});

/**
 * The refusal a controller type-hinting the callback would receive, provoked
 * through the container exactly as that controller would.
 *
 * A refusal rather than a skip when nothing was raised: a provocation that
 * stopped provoking would leave every assertion below reading an empty trace and
 * reporting green, which is the direction that hides the leak.
 */
function callbackRefusalOutsideRequest(): ConfigurationException
{
    vposConfig();

    try {
        app(VposCallback::class);
    } catch (ConfigurationException $failure) {
        return $failure;
    }

    throw new RuntimeException(
        'Resolving the callback outside a request raised no ConfigurationException, so this file inspected the '
        .'trace of nothing. Every assertion here would pass without a frame having been read.',
    );
}

/**
 * The frame $depth calls below the one that built the refusal, or a refusal of
 * its own.
 *
 * A missing frame is not a pass. A trace too short to hold the frame this file
 * is about would leave every assertion below reading nothing and reporting
 * green, which is the direction the defect this file exists for survives in.
 *
 * @return array<array-key, mixed>
 */
function refusalFrame(ConfigurationException $failure, int $depth): array
{
    $frame = $failure->getTrace()[$depth] ?? null;

    if ($frame === null) {
        throw new RuntimeException(sprintf(
            'The callback refusal carries no frame %d, so the call this guard is about cannot be inspected and '
            .'every assertion about it would be about nothing.',
            $depth,
        ));
    }

    return $frame;
}

/**
 * Whether a trace frame is a call to a method this package declares.
 *
 * Both halves are asked of reflection rather than of a name written here. A
 * closure defined inside a class reports that class and a function name that is
 * no method of it — `{closure}` on PHP 8.3, `{closure:file:line}` from 8.4 — so
 * this tells a declared method from a closure without depending on either
 * spelling.
 *
 * @param  array<array-key, mixed>  $frame
 */
function isDeclaredPackageMethod(array $frame): bool
{
    $class = $frame['class'] ?? null;
    $function = $frame['function'] ?? null;

    if (! is_string($class) || ! is_string($function) || ! class_exists($class)) {
        return false;
    }

    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    if ($file === false || ! in_array($file, ProductionClasses::files(), true)) {
        return false;
    }

    return $reflection->hasMethod($function)
        && $reflection->getMethod($function)->getDeclaringClass()->getName() === $class;
}

it('raises the callback refusal from a frame that carries nothing', function (): void {
    $frame = refusalFrame(callbackRefusalOutsideRequest(), 1);
    $arguments = $frame['args'] ?? null;

    // Which frame this is, before anything is said about what it holds — and
    // asked of reflection, not compared against a method name written here.
    expect(isDeclaredPackageMethod($frame))->toBeTrue(sprintf(
        'Frame 1 of the callback refusal is %s%s%s, which is not a method this package declares. The frame under '
        .'the factory used to be the provider helper that raised it; if the call shape has changed, this guard is '
        .'now reading somebody else\'s frame and asserting nothing about this package.',
        is_string($frame['class'] ?? null) ? $frame['class'] : '',
        is_string($frame['type'] ?? null) ? $frame['type'] : ' ',
        is_string($frame['function'] ?? null) ? $frame['function'] : '(unnamed)',
    ));

    // The per-subject half of the INI pin, and it refuses rather than passes.
    // Under php.ini-production `args` is absent from every frame, the equality
    // below would compare [] with [], and this file would be green while
    // reading nothing at all — the exact state a leak survives.
    //
    // array_key_exists() rather than toHaveKey(), which takes the expected
    // *value* as its second argument and would assert that args equals the
    // sentence explaining the assertion.
    expect(array_key_exists('args', $frame))->toBeTrue(
        'Frame 1 records no arguments, so this test proves nothing about what the raising method was handed. '
        .'zend.exception_ignore_args has been turned on somewhere between phpunit.xml.dist and here; restore the '
        .'pin rather than weakening what depends on it.',
    );

    expect($arguments)->toBe([], sprintf(
        'The method that raised the callback refusal was called with arguments, and this refusal is one of the '
        .'two that escape to a reporter: it is logged with its trace rendered and forwarded to '
        .'whatever reporter is installed. If %s reaches the container or the configuration repository, the live '
        .'credentials are one hop from a log line. Take no parameter and read $this->app instead.',
        is_string($frame['function'] ?? null) ? $frame['function'] : 'that method',
    ));
});

it('carries the container in the framework frame no parameter list can empty', function (): void {
    $failure = callbackRefusalOutsideRequest();
    $depth = 0;

    foreach ($failure->getTrace() as $index => $frame) {
        $depth = $index;

        if (! isDeclaredPackageMethod($frame)) {
            break;
        }

        // Every frame so far is a method this package declares, which cannot
        // stay true to the bottom of a trace — the container is what called
        // into this package. refusalFrame() refuses if it ever does.
        $depth = $index + 1;
    }

    $residue = refusalFrame($failure, $depth);
    $arguments = $residue['args'] ?? null;

    // It belongs to this package and is not a method of it: the closure
    // register() hands to bind(). Its class is asserted so that "the first
    // frame that is not ours" cannot silently become a framework frame further
    // down, which would make the argument assertion below meaningless.
    expect($residue['class'] ?? null)->toBeIn(ProductionClasses::all(), sprintf(
        'The first frame beneath this package\'s own methods belongs to %s. It used to be the binding closure '
        .'this provider registers; if the call shape has changed, what this test measures has changed with it.',
        is_string($residue['class'] ?? null) ? $residue['class'] : '(no class)',
    ));

    expect(array_key_exists('args', $residue))->toBeTrue(
        'The closure frame records no arguments, so nothing here can say what the container passed it. '
        .'zend.exception_ignore_args has been turned on somewhere between phpunit.xml.dist and here.',
    );

    // Asserted as present, not as absent. The container passes itself and the
    // parameter overrides to every closure it builds from, whatever that
    // closure declares — measured on the Vpos binding, whose closure declares
    // no parameters and whose frame carries both regardless. No parameter list
    // removes this frame or empties it, which is why README describes it to
    // merchants rather than promising it away. A run in which this equality
    // fails is a run in which that description became false.
    expect($arguments)->toBe([app(), []], sprintf(
        'The binding closure\'s frame no longer carries the container and the parameter overrides the container '
        .'passes it. That is what %s documents as the residue this package cannot remove: it is the framework\'s '
        .'own call, not one this package writes. If it has genuinely changed, the sentence in README.md that '
        .'tells merchants to expect it has to change in the same commit — this package must not claim a frame is '
        .'there when it is not, any more than it may claim one is clean when it is not.',
        is_string($residue['function'] ?? null) ? $residue['function'] : 'the closure frame',
    ));
});

/**
 * A trace frame written as one line, for a failure message to list.
 *
 * @param  array<array-key, mixed>  $frame
 */
function frameLabel(int $index, array $frame): string
{
    $types = [];

    foreach (is_array($frame['args'] ?? null) ? $frame['args'] : [] as $argument) {
        // get_debug_type() answers with the class name for an object and with
        // the type name for everything else, which is exactly the two things
        // this label wants and in the same call.
        $types[] = get_debug_type($argument);
    }

    return sprintf(
        '%d %s%s%s(%s)',
        $index,
        is_string($frame['class'] ?? null) ? $frame['class'] : '',
        is_string($frame['type'] ?? null) ? $frame['type'] : ' ',
        is_string($frame['function'] ?? null) ? $frame['function'] : '(unnamed)',
        implode(', ', $types),
    );
}

/**
 * The failure that escapes `vpos:check` from the statements before its `try`.
 *
 * The command is run the way the framework runs it — `Command::run()`, which
 * calls `handle()` through `Container::call()` — because the frames this guard
 * reads are the container's own and a direct method call would not produce
 * them.
 *
 * A refusal rather than a skip when nothing escapes, and a refusal when
 * something other than the provoked failure does. Either would leave every
 * assertion below reading a trace this file is not about, and reporting green
 * for it.
 */
function preTryOutputEscape(): Throwable
{
    vposConfig();

    $command = app(CheckCommand::class);
    $command->setLaravel(app());
    $escaped = null;

    try {
        $command->run(new ArrayInput([]), new BrokenOutput);
    } catch (Throwable $failure) {
        $escaped = $failure;
    }

    if (! $escaped instanceof Throwable || $escaped->getMessage() !== BrokenOutput::failureMessage()) {
        throw new RuntimeException(sprintf(
            'Running the check command against an output that cannot be written raised %s rather than the '
            .'provoked write failure, so the trace read below is not the escape this guard is about and every '
            .'assertion in it would be about something else. Either the command no longer writes before it opens '
            .'its try — in which case the paragraph above about that region is out of date — or the escape is now '
            .'caught somewhere it was not.',
            $escaped instanceof Throwable ? $escaped::class.': '.$escaped->getMessage() : 'nothing at all',
        ));
    }

    return $escaped;
}

it('escapes the check command through frames of its own that carry nothing', function (): void {
    $failure = preTryOutputEscape();
    $container = app();

    $ours = [];
    $carried = [];
    $carrying = [];
    $unreadable = [];

    foreach ($failure->getTrace() as $index => $frame) {
        $mine = isDeclaredPackageMethod($frame);
        $arguments = is_array($frame['args'] ?? null) ? $frame['args'] : [];

        if ($mine) {
            $ours[] = frameLabel($index, $frame);

            if (! array_key_exists('args', $frame)) {
                $unreadable[] = frameLabel($index, $frame);
            } elseif ($arguments !== []) {
                $carried[] = frameLabel($index, $frame);
            }
        }

        foreach ($arguments as $argument) {
            if ($argument === $container) {
                $carrying[] = frameLabel($index, $frame);

                break;
            }
        }
    }

    // The provocation reaches this package at all. A trace in which no frame of
    // this package's appears is one where the command failed somewhere else,
    // and every assertion below would be about the framework.
    expect($ours)->not->toBeEmpty(
        'The failure provoked out of the check command passes through no method this package declares, so this '
        .'guard is reading somebody else\'s trace and says nothing about what this package puts in one.'
    );

    // The per-subject half of the INI pin, and it refuses rather than passes.
    // Under php.ini-production `args` is absent from every frame, the emptiness
    // below would be emptiness for the wrong reason, and this file would report
    // green while reading nothing at all.
    expect($unreadable)->toBe([], sprintf(
        "These frames record no arguments, so nothing here can say what they were handed:\n%s\n"
        .'zend.exception_ignore_args has been turned on somewhere between phpunit.xml.dist and here; restore the '
        .'pin rather than weakening what depends on it.',
        implode("\n", $unreadable),
    ));

    expect($carried)->toBe([], sprintf(
        "The check command escapes through a frame of its own that was called with arguments:\n%s\n"
        .'This escape reaches a reporter: the console kernel catches it, reports it through the application\'s '
        .'exception handler, and a reporter that walks getTrace() serialises whatever those arguments are. The '
        .'command\'s own catch clause does not cover the statements that run before its try opens. If the frame '
        .'is handle(), it has been given parameters again — resolve what it needs inside the try at the point of '
        .'use, as it does now. If it is a helper called from that region, either it belongs inside the try or its '
        .'arguments have to be reduced to something that is not a value.',
        implode("\n", $carried),
    ));

    // Asserted as present, not as absent, exactly as the binding closure above
    // is. Laravel calls a command's handle() through Container::call(), which
    // passes the application to its own helpers; no parameter list this package
    // writes removes those frames or empties them. A run in which this is empty
    // is a run in which the paragraph describing the residue became false.
    expect($carrying)->not->toBeEmpty(
        'No frame in the escape carries the application container. That is the residue this file records as '
        .'beyond the package\'s reach — the framework\'s own call into the command — and a guard that stopped '
        .'seeing it would be describing a trace that no longer exists. If the framework genuinely stopped '
        .'passing it, the paragraph above has to change in the same commit.'
    );
});
