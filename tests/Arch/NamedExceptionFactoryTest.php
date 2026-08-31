<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;

/*
 * Every message this package can emit is written in exactly one named factory.
 *
 * ConfigurationException already enforces that for itself — its constructor is
 * private, so a call site cannot reach `new ConfigurationException(...)` and
 * invent a message variant. But that is a statement about one class, and the
 * rule is about the package: nothing stops the next exception class from being
 * built inline, and nothing stops a `throw new RuntimeException('...')` from
 * appearing three commits from now in a file nobody reads again. At that point
 * the set of things this package can say to a merchant stops being enumerable
 * by reading the exception classes, which is the whole property the private
 * constructor was bought for.
 *
 * `throw new` is the construct this guard holds. It holds that construct
 * exactly — the token pair T_THROW T_NEW — and nothing wider:
 *
 *   - a factory does `return new self(...)`, which is `new` without `throw` and
 *     is therefore untouched by this rule — the message is still written in one
 *     named place, which is what the rule asks for;
 *   - `throw ConfigurationException::blankBackUrl()` is `throw` without `new`,
 *     which is the compliant form and the form every throw site in src/ takes
 *     today;
 *   - `throw $failure`, where `$failure` was assigned a freshly constructed
 *     exception a line earlier, composes the message at the throw site and
 *     **passes this guard**. So does an immediately-invoked closure returning
 *     one. Both are the rule's own subject and neither is caught.
 *
 * That gap is stated here rather than papered over, because a comment claiming
 * more than its tokens prove is the same defect as a rule nothing holds, which
 * is the defect this file exists to end. What is held: no production file
 * throws a freshly constructed exception in one statement. What is not held:
 * the wider rule this test is named for, since an exception bound to a variable
 * first reaches the throw site by a route two adjacent tokens cannot see.
 *
 * Closing it means matching `new` on any name that resolves to a Throwable and
 * permitting it only inside a Throwable's own class — a wider rule than the one
 * specified, and a design change rather than a correction, so it is recorded as
 * an open finding instead of being made here. It is prospective, not a live
 * breach: `src/` today constructs an exception in named factories only, and
 * every throw site returns one of them.
 *
 * Why this is a tokenised sweep and not an arch() expectation: pest-plugin-arch
 * expresses relationships between symbols — what a class extends, implements,
 * uses, depends on. `throw new` is a *statement form*, not a symbol; there is
 * no class or function to name, and forbidding the exception classes themselves
 * through toBeUsed() would forbid the factories too, which are the thing the
 * rule exists to require. No native expectation covers this ground, so the
 * standing prohibition on hand-rolling one does not apply.
 *
 * Tokens rather than a text search, for the same reason as every other sweep
 * here: a token stream cannot match the words "throw new" inside a string, a
 * comment or a docblock — including inside this comment, which a grep-based
 * guard would report as a violation of itself.
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
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $total = count($tokens);

        for ($index = 0; $index + 1 < $total; $index++) {
            if ($tokens[$index]->is(T_THROW) && $tokens[$index + 1]->is(T_NEW)) {
                $found[] = sprintf('%s:%d', $file, $tokens[$index]->line);
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
