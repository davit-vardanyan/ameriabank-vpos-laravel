<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;

/*
 * Nothing this package ships is marked internal.
 *
 * ## Why this is a rule rather than a preference
 *
 * `BackUrlResolver` carried the marking, and it was the wrong call in a way
 * that took a whole design decision to unpick. The core's `InitPaymentRequest`
 * takes `backUrl` as a required constructor argument, so a merchant told the
 * resolver was not for them passed `route(...)` instead — which left
 * `ameriabank-vpos.back_url` read by no code path a payment executes. The key
 * was inert for real traffic and load-bearing only for `vpos:check`, and the
 * two values could drift apart silently: a config naming one route and a
 * controller passing another produced a diagnostic reporting a BackURL no
 * payment would ever carry.
 *
 * That is what the marking bought. What it cost was the only supported way to
 * build the value, withheld from the people who needed it.
 *
 * ## What the marking actually does, and why it is not free
 *
 * It is not a comment. PhpStorm greys the symbol out at every call site outside
 * this package and warns on use; PHPStan's `internal` rules report the same;
 * `roave/backward-compatibility-check` treats a marked symbol as exempt from
 * semantic versioning, so a breaking change to it is not reported as one. So a
 * class that is really part of the public surface and carries the marking gets
 * a merchant told not to use it *and* no protection when it changes — the worst
 * of both, and the second half is invisible until a release breaks somebody.
 *
 * ## Why the whole package rather than the one class
 *
 * The acceptance criterion names `BackUrlResolver`, and a guard naming
 * `BackUrlResolver` would go on passing the day the marking is applied to
 * something else for the same bad reason. A subject list derived from the
 * package's own PSR-4 map cannot: a new production file joins it without
 * anybody editing this test.
 *
 * This is deliberately a decision point rather than a ban. A future symbol that
 * genuinely should be internal makes this red, and that is the intent — the
 * marking has a real cost, it has been paid once here, and the next one should
 * be argued for rather than typed.
 *
 * ## Tokens rather than a text search
 *
 * A grep-based sweep would match the word inside this very comment if the file
 * ever moved into `src/`, and would match it inside a string literal or an
 * ordinary comment there. Only doc comments carry the annotation's meaning, so
 * only doc comments are read.
 *
 * ## Mutation testing cannot see any of this
 *
 * Mutants remove and invert code; they never add an annotation. This guard
 * asserts an absence, so no mutation score will ever report on it and the only
 * evidence it works is the demonstration recorded against it — the marking
 * restored to `BackUrlResolver` by hand, this test seen red, and the file
 * restored from a checksummed snapshot.
 */
it('marks nothing it ships as internal', function (): void {
    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen an internal '
        .'marking even if one existed.'
    );

    $marked = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is(T_DOC_COMMENT)) {
                continue;
            }

            if (str_contains($token->text, '@internal')) {
                $marked[] = sprintf('%s:%d', $file, $token->line);
            }
        }
    }

    expect($marked)->toBe([], sprintf(
        "Production code marks a symbol internal:\n%s\n"
        .'Everything this package ships is public API. The marking is not a comment: an IDE greys the symbol '
        .'out at every call site outside this package, PHPStan reports its use, and a backward-compatibility '
        .'check exempts it from semantic versioning — so a symbol that is really public and carries the marking '
        .'gets merchants told not to use it and no protection when it changes. BackUrlResolver carried it, and '
        .'the result was a configuration key no payment path read. If this symbol genuinely should be internal, '
        .'that is a decision to argue for and record, not one to type.',
        implode("\n", $marked),
    ));
});
