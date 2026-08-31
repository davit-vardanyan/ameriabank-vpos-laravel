<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ExceptionFactories;

/*
 * No exception factory in this package accepts a value.
 *
 * ## The defect this exists for, which had already shipped once
 *
 * A factory builds its exception inside its own body, so **the factory's own
 * call is frame 0 of the trace that exception carries**, and a frame records
 * the arguments its function was called with. `getTraceAsString()` renders
 * them, and Laravel's default log formatter is constructed with stack traces
 * enabled — so whatever a factory was handed is written to the application's
 * log.
 *
 * `notAString(string $key, mixed $actual)` was exactly that shape. It kept the
 * configured value out of the *message*, deliberately and with a docblock
 * explaining why, and then took the value as a parameter — which put it in the
 * trace instead. A password set to an array reached
 * `storage/logs/laravel.log` verbatim. Two more factories had the same
 * signature with lower exposure.
 *
 * The exception class defends the *other* route already: `__serialize()`
 * narrows the trace through `safeFrames()` and drops `args` entirely. That
 * covers an exception that is queued, cached or otherwise persisted, and it
 * does nothing at all for one that is merely logged, because `__serialize()`
 * never runs on that path. Neither half is sufficient alone.
 *
 * ## Why a signature rule rather than a search for the leak
 *
 * Because a signature is what makes the leak *unrepresentable*. PHP enforces
 * parameter types at the call site, so `string $type` cannot be handed the
 * array, the object or the integer that a configured value may be — the value
 * has to be reduced to a type name before the boundary, which is where
 * `get_debug_type()` now runs. A guard that instead looked for the value in the
 * trace would be an absence assertion: unobservable by mutation, and vacuous
 * under an INI that suppresses arguments. This one is neither.
 *
 * `ConfigurationExceptionTraceTest` is the behavioural half of the same claim,
 * and holds what this cannot: that a real refusal's frame 0 carries what it is
 * supposed to. This half holds what *that* cannot — every factory, including
 * ones no configuration can currently provoke, and the shape of the next one
 * somebody writes.
 *
 * ## What is allowed, and why each entry is
 *
 * A scalar. `string`, `int`, `float` and `bool` are the types a key name, a
 * type name and a numeric bound arrive as. A scalar can still be a value — a
 * credential is a string — so this is not the whole defence; it is the half
 * that stops a whole class of value from crossing at all, and the behavioural
 * guard is the half that reads what actually crossed.
 *
 * A `Throwable`. Every factory that wraps another failure chains it, and the
 * cause is the whole point of chaining: it carries the framework's own account
 * of the mistake, which the message deliberately declines to repeat.
 * `__serialize()` drops it rather than publishing it, and `chainDropped()`
 * records that it did.
 *
 * Everything else is refused by name — `mixed`, `array`, `object`, `iterable`,
 * a union, an intersection, an arbitrary class, and no type at all. Those are
 * the shapes a configured value, a container binding or a request arrives in.
 *
 * The subject list is `ExceptionFactories::all()`, derived from composer.json's
 * PSR-4 map at test time, so a factory added tomorrow is inspected without this
 * file being edited. Nothing here names a factory.
 */
/**
 * A parameter's declared type, as a sentence a failure message can carry.
 *
 * Composed rather than cast. `(string) $type` reads the same and is deprecated
 * on ReflectionType as of PHP 8.5, so a guard written that way would go from
 * green to a deprecation the suite fails on — and this package's phpunit
 * configuration sets failOnDeprecation, so it would fail the whole run rather
 * than warn.
 *
 * Every branch exists because a parameter can really be declared that way. The
 * composite branches are the ones that matter to the rule: a union is how a
 * value-carrying type gets smuggled past a whitelist that only ever inspects a
 * single name.
 */
function describeParameterType(?ReflectionType $type): string
{
    if ($type instanceof ReflectionNamedType) {
        return $type->getName();
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $parts = [];

        foreach ($type->getTypes() as $part) {
            $parts[] = $part instanceof ReflectionNamedType ? $part->getName() : 'a nested composite type';
        }

        return implode($type instanceof ReflectionUnionType ? '|' : '&', $parts);
    }

    return $type instanceof ReflectionType ? 'a type this guard has no name for' : 'with no type at all';
}

it('lets no exception factory take a parameter that could carry a value', function (): void {
    $safeScalars = ['string', 'int', 'float', 'bool'];

    $offenders = [];
    $inspected = 0;

    foreach (ExceptionFactories::all() as $factory => $method) {
        foreach ($method->getParameters() as $parameter) {
            $inspected++;

            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $named = $type->getName();

                if (in_array($named, $safeScalars, true)) {
                    continue;
                }

                if (! $type->isBuiltin() && is_a($named, Throwable::class, true)) {
                    continue;
                }
            }

            $offenders[] = sprintf(
                '%s($%s) is declared %s',
                $factory,
                $parameter->getName(),
                describeParameterType($type),
            );
        }
    }

    expect($inspected)->toBeGreaterThan(
        0,
        'Every factory this guard found takes no parameters at all, so it inspected nothing and would have '
        .'reported success against a package whose factories all took the value. Either the derivation broke or '
        .'the classes it walks are not the ones this rule is about.',
    );

    expect($offenders)->toBe([], sprintf(
        "An exception factory takes a parameter that can carry a value:\n%s\n"
        .'A factory builds its exception inside its own body, so its own call is frame 0 of that exception\'s '
        .'trace, and a frame records the arguments it was called with. getTraceAsString() renders them and '
        .'Laravel\'s default log formatter includes stack traces, so anything handed to a factory is written to '
        .'the application log — which is the one place this package keeps credentials out of. Resolve the value '
        .'to a type name with get_debug_type() at the throw site and give the factory a string; the value then '
        .'never crosses the boundary rather than being filtered after it has. A parameter that must carry '
        .'something richer than a scalar is a Throwable being chained, and nothing else.',
        implode("\n", $offenders),
    ));
});
