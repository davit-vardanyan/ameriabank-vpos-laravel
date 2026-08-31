<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;

/*
 * The line coverage floor may not go missing, and may not go down.
 *
 * `composer coverage:check` is the only thing that turns a coverage number
 * into a failing exit code. It is an inline `@php -r` one-liner in
 * composer.json rather than a vendor binary or a script file, so the 100 it
 * compares against is a bare literal with no tool owning it, and both ways of
 * weakening it are quiet: lower the number and the gate passes at a coverage
 * this package does not accept; drop the exit() and the script prints a
 * coverage line and exits 0 whatever it read. Neither shows up anywhere else
 * in the nine commands.
 *
 * This is the same class of gap as the unguarded mutation score floor next
 * door, at lower stakes, and it is guarded the same way and for the same
 * reason: a floor that nothing checks is a floor that can be removed by
 * accident and never noticed, because its removal looks like success.
 *
 * How it reads the script, and why that way:
 *
 * The script's value is *double* escaped in composer.json's raw bytes, and the
 * governing rule is that it is copied byte-for-byte and never hand-retyped,
 * because retyping it with one backslash or none makes the variables
 * interpolate. This guard therefore never touches those bytes: json_decode()
 * performs the one decoding step, exactly as Composer does before running the
 * script, and the pattern is applied to the decoded string. Nothing here can
 * be affected by, or tempt anyone to normalise, the escaping.
 *
 * That argument is about the *number of decoding steps*, not about who
 * performs them, so it does not reach the read itself.
 * `ProductionClasses::manifest()` is `file_get_contents()` followed by one
 * `json_decode($raw, true, 512, JSON_THROW_ON_ERROR)` — the same single step,
 * with no normalising of its own — and `MutationEscapeHatchTest` already reads
 * the equally escape-sensitive `mutate` script through it. Keeping a private
 * copy of twelve lines here would have bought nothing and would have left this
 * the one guard in the suite that reads the manifest its own way, which is the
 * drift the helper exists to end.
 *
 * The pattern deliberately pins the comparison and both exit codes rather than
 * hunting for a loose "100" anywhere in the script — the script already
 * contains other numbers, and a match on the wrong one would be a guard that
 * cannot fail. What it asserts is the whole decision: less-than the floor
 * exits 1, otherwise 0. A rewrite that inverts or restructures that decision
 * stops matching and fails red, which is the safe direction; the guard has no
 * quiet failure mode.
 */
it('holds the line coverage floor in composer.json at exactly 100', function (): void {
    $manifestPath = ProductionClasses::root().'/composer.json';
    $manifest = ProductionClasses::manifest();

    $scripts = $manifest['scripts'] ?? null;

    if (! is_array($scripts) || ! array_key_exists('coverage:check', $scripts)) {
        throw new RuntimeException(sprintf('%s declares no "coverage:check" script.', $manifestPath));
    }

    $check = $scripts['coverage:check'];
    $commands = is_array($check) ? $check : [$check];
    $sources = [];
    $thresholds = [];

    foreach ($commands as $command) {
        if (! is_string($command)) {
            throw new RuntimeException('The "coverage:check" script is not a string or a list of strings.');
        }

        $sources[] = $command;

        $found = preg_match_all('/exit\(\s*\S+\s*<\s*(\d+(?:\.\d+)?)\s*\?\s*1\s*:\s*0\s*\)/', $command, $matches);

        if ($found === false) {
            throw new RuntimeException('Unable to scan the "coverage:check" script for its coverage threshold.');
        }

        foreach ($matches[1] as $threshold) {
            $thresholds[] = $threshold;
        }
    }

    expect(count($thresholds))->toBe(1, sprintf(
        'The "coverage:check" script in composer.json contains %d comparison(s) that fail below a coverage threshold; exactly one is expected. '
        .'With none, the script reports a coverage percentage and exits 0 no matter how low it is, and the gate command that enforces 100%% line coverage enforces nothing. '
        .'The script is: %s',
        count($thresholds),
        implode(' ', $sources),
    ));

    /*
     * Iterated rather than indexed: the expectation above has already pinned
     * the count at one, and walking the list keeps the value read out of the
     * same place it was found instead of reaching for an offset that the
     * reader has to prove exists.
     */
    foreach ($thresholds as $threshold) {
        expect((float) $threshold)->toBe(100.0, sprintf(
            'The "coverage:check" script in composer.json fails below %s%% line coverage. The floor is 100 and may not be lowered.',
            $threshold,
        ));
    }
});
