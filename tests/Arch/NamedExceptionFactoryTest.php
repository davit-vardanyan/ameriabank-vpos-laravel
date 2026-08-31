<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionSource;

/*
 * Every message this package can emit is written in exactly one named factory.
 *
 * ConfigurationException already enforces that for itself — its constructor is
 * private, so a call site cannot reach `new ConfigurationException(...)` and
 * invent a message variant. But that is a statement about one class, and the
 * rule is about the package: nothing stops the next exception class from being
 * built inline, and nothing stops a freshly constructed RuntimeException from
 * appearing three commits from now in a file nobody reads again. At that point
 * the set of things this package can say to a merchant stops being enumerable
 * by reading the exception classes, which is the whole property the private
 * constructor was bought for.
 *
 * ## Two expectations, and why the second one had to be added
 *
 * The rule was held for one task by the token pair `throw` `new` and nothing
 * wider, and this comment recorded the gap that left rather than papering over
 * it: an exception assigned to a variable and thrown a line later composes its
 * message at the throw site and passed. The bypass was three tokens long.
 *
 *     $failure = new RuntimeException('composed right here');
 *     throw $failure;
 *
 * So the rule the file is named for is now held by the second expectation
 * below: **a Throwable may be constructed only inside a Throwable**, which is
 * what a named factory is — `ConfigurationException::blankBackUrl()` returns
 * `new self(...)` from inside ConfigurationException, and every throw site
 * calls a factory and throws its result. A construction anywhere else composes
 * a message outside the class that owns it, whether it is thrown on the same
 * line, on the next one, or returned from a closure.
 *
 * **Both expectations are kept, and the first is not redundant.** The widened
 * rule permits everything inside a Throwable's own body, so a literal
 * `throw new RuntimeException(...)` written *inside* an exception class would
 * satisfy it. That is the one case the narrow rule already caught, and losing a
 * case while widening is not widening. The narrow rule keeps it.
 *
 * ## How a name in the token stream becomes a Throwable
 *
 * `ProductionSource::constructions()` applies PHP's own resolution rules — the
 * current namespace, `use` imports including aliased and grouped ones, and
 * `self`, `static` and `parent` — before this file asks `is_a()` anything. A
 * guard that matched only unqualified names would be a new bypass on the day
 * somebody wrote a leading backslash or imported the class under another name,
 * and a guard that resolved names by guessing would report a guess as a fact.
 * A construction whose class the source does not state — a variable class name,
 * an expression — resolves to null and is not reported, because nothing textual
 * can know what it builds.
 *
 * `is_a($name, Throwable::class, true)` rather than `is_subclass_of()`: the two
 * agree on every subclass, and differ only in that `is_a()` also answers true
 * for Throwable itself. Interfaces count for both, which is what makes
 * `Exception` and every PSR exception interface resolve correctly.
 *
 * ## Why this is a tokenised sweep and not an arch() expectation
 *
 * pest-plugin-arch expresses relationships between symbols — what a class
 * extends, implements, uses, depends on. Constructing an exception is a
 * *statement form*, not a symbol relationship; forbidding the exception classes
 * themselves through toBeUsed() would forbid the factories too, which are the
 * thing the rule exists to require. No native expectation covers this ground,
 * so the standing prohibition on hand-rolling one does not apply.
 *
 * Tokens rather than a text search, for the same reason as every other sweep
 * here: a token stream cannot match the words inside a string, a comment or a
 * docblock — including inside this comment, which a grep-based guard would
 * report as a violation of itself.
 *
 * ## Mutation testing cannot see either expectation
 *
 * Both assert an absence, and mutants remove and invert code rather than adding
 * it, so no mutation score will ever report on them. The only evidence they
 * work is the demonstration recorded against them: the assign-then-throw pair
 * above inserted into `BackUrlResolver` by hand and this file seen red, the
 * literal `throw new` form inserted the same way and seen red, and the file
 * restored from a checksummed snapshot each time.
 *
 * The subject list is ProductionClasses::files(), so it is the same source of
 * truth every other guard derives from and a new production file joins it
 * without anybody editing this test.
 */
it('composes no exception message at a throw site', function (): void {
    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen a "throw new" even if one existed.'
    );

    $found = [];

    foreach ($files as $file) {
        foreach (ProductionSource::constructions($file) as $construction) {
            if ($construction['thrown']) {
                $found[] = sprintf('%s:%d', $file, $construction['line']);
            }
        }
    }

    expect($found)->toBe([], sprintf(
        "Production code builds an exception at the throw site:\n%s\n"
        .'Every message this package can emit belongs in a named factory on the exception class, so the set of things it can say stays enumerable by reading that class. '
        .'Add a factory and throw its result instead — `throw SomeException::whatWentWrong($context)`.',
        implode("\n", $found),
    ));
});

it('constructs a throwable only inside a throwable', function (): void {
    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen an exception constructed outside a factory even if one existed.'
    );

    $found = [];

    foreach ($files as $file) {
        foreach (ProductionSource::constructions($file) as $construction) {
            $name = $construction['name'];

            if ($name === null || ! class_exists($name) || ! is_a($name, Throwable::class, true)) {
                continue;
            }

            $enclosing = $construction['enclosing'];

            if ($enclosing !== null && is_a($enclosing, Throwable::class, true)) {
                continue;
            }

            $found[] = sprintf(
                '%s:%d builds %s inside %s',
                $file,
                $construction['line'],
                $name,
                $enclosing ?? 'no class at all',
            );
        }
    }

    expect($found)->toBe([], sprintf(
        "Production code constructs an exception outside an exception class:\n%s\n"
        .'A named factory constructs its own class from inside it, which is what keeps every message this package can emit enumerable by reading the exception class. '
        .'Building one anywhere else composes the message at the call site — whether it is thrown on the same statement, assigned to a variable and thrown on the next, or returned from a closure. '
        .'Add a factory to the exception class and call it instead.',
        implode("\n", $found),
    ));
});
