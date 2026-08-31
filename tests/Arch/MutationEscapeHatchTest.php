<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use Pest\Mutate\Options\IgnoreMinScoreOnZeroMutationsOption;
use Pest\Mutate\Options\MinScoreOption;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputDefinition;

/*
 * The zero-mutation escape hatch may not outlive its reason — and it did not.
 *
 * `composer mutate` once carried --ignore-min-score-on-zero-mutations, for a
 * reason that was narrow and openly temporary. The package shipped a single
 * final class holding a constant and an empty register(), which generates no
 * mutants at all, and the plugin's MutationRepository::score() returns 0 when
 * total() === 0 — so an unguarded run scored 0.00% against a floor of 100 and
 * exited 1. The flag suppressed that one case and nothing else; the floor
 * stayed --min=100 throughout.
 *
 * That reason has since expired. `src/` now carries real production code, the
 * flag is gone from composer.json, and the mutation gate is scored against
 * mutants that actually exist. This guard is why the removal happened when the
 * reason ended rather than whenever somebody next happened to re-read the
 * script.
 *
 * It stays because the removal is not self-sustaining. "Inert" was never
 * "harmless": the flag re-added — restored from an old branch, copied from the
 * package this one was modelled on, or reached for the next time a run reports
 * no mutants — would silently absolve a `src/` that produced none for the wrong
 * reason: a broken --path, a renamed namespace, a filter matching nothing. That
 * failure has no other symptom, because it fails green. A note in a document
 * somebody has to read first is not enforcement, so the rule is asserted here
 * instead.
 *
 * The assertion is unchanged in either direction, and deliberately so: the flag
 * and mutatable production code may not both be present. Today the flag is
 * absent, so it passes against a `src/` that declares many mutatable methods;
 * put the flag back and it fails, naming the methods it found.
 *
 * Why this is a reflection guard and not an arch() expectation: the rule
 * relates production code to the *build manifest*, and no arch() expectation
 * can read composer.json. There is therefore no native expectation being
 * duplicated, and the standing prohibition on hand-rolling reflection guards
 * where arch() already covers the ground does not apply here.
 *
 * Both sides derive from their source of truth at test time:
 *   - the subject list comes from ProductionClasses::all(), which walks
 *     composer.json's autoload.psr-4 map over the filesystem and verifies
 *     that every name it yields autoloads; it is reflected here — never a
 *     list of class or method names written down here;
 *   - the flag comes from a real json_decode() of composer.json, tokenised
 *     into arguments and matched with the plugin's own
 *     IgnoreMinScoreOnZeroMutationsOption::match(), so the spelling of the
 *     flag is owned by the plugin rather than restated as a literal.
 *
 * It reads only composer.json, which is tracked and ships in every dist, and
 * nothing a gate run produces. It is green on a fresh clone.
 */
it('drops the zero-mutation escape hatch as soon as src carries mutatable code', function (): void {
    /*
     * A method body is mutatable when it contains at least one token that is
     * not whitespace or a comment. The body is located by tokenising the exact
     * source lines the method spans and taking everything between the first {
     * and the last }. Anything that cannot be read or does not look like a
     * body is reported as mutatable, so the ambiguous case fails loudly rather
     * than excusing the flag.
     */
    $hasEmptyBody = static function (ReflectionMethod $method): bool {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        if ($file === false || $start === false || $end === false) {
            return false;
        }

        $lines = file($file);

        if ($lines === false) {
            return false;
        }

        $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        $tokens = array_values(PhpToken::tokenize('<?php '.$source));
        $total = count($tokens);

        $open = null;
        $close = null;

        for ($index = 0; $index < $total; $index++) {
            if ($tokens[$index]->text === '{') {
                $open = $index;
                break;
            }
        }

        for ($index = $total - 1; $index >= 0; $index--) {
            if ($tokens[$index]->text === '}') {
                $close = $index;
                break;
            }
        }

        if ($open === null || $close === null || $close <= $open) {
            return false;
        }

        for ($index = $open + 1; $index < $close; $index++) {
            if (! $tokens[$index]->isIgnorable()) {
                return false;
            }
        }

        return true;
    };

    $manifestPath = ProductionClasses::root().'/composer.json';
    $manifest = ProductionClasses::manifest();

    // --- The flag, read out of the manifest the gate actually runs. ---

    $scripts = $manifest['scripts'] ?? null;

    if (! is_array($scripts) || ! array_key_exists('mutate', $scripts)) {
        throw new RuntimeException(sprintf('%s declares no "mutate" script.', $manifestPath));
    }

    $mutate = $scripts['mutate'];
    $commands = is_array($mutate) ? $mutate : [$mutate];
    $flagIsPresent = false;

    foreach ($commands as $command) {
        if (! is_string($command)) {
            throw new RuntimeException('The "mutate" script is not a string or a list of strings.');
        }

        $arguments = explode(' ', str_replace(["\t", "\r", "\n"], ' ', $command));

        foreach ($arguments as $argument) {
            if (IgnoreMinScoreOnZeroMutationsOption::match($argument)) {
                $flagIsPresent = true;
            }
        }
    }

    // --- The subjects, read out of the same manifest's production autoload map. ---

    /*
     * The walk lives in ProductionClasses::all() and exists only there. It
     * reads this same manifest, and only PSR-4 is walked: if production code
     * ever arrives through another autoload mechanism the helper refuses to
     * run rather than going blind to it silently. Every name it yields is
     * verified to autoload before it is returned, so what comes back is the
     * production code this package actually ships.
     *
     * A second copy of that walk living here would be a subject list this
     * guard maintains by hand in all but name: it would keep passing while
     * drifting away from the one every other guard derives its subjects from,
     * and the drift would show as production code quietly absent from the
     * list rather than as a failure.
     */
    $classNames = ProductionClasses::all();

    $mutatable = [];

    foreach ($classNames as $className) {
        $reflection = new ReflectionClass($className);

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if ($method->isConstructor() || $method->isAbstract()) {
                continue;
            }

            if (! $method->isPublic() && ! $method->isProtected()) {
                continue;
            }

            if ($hasEmptyBody($method)) {
                continue;
            }

            $mutatable[] = $reflection->getShortName().'::'.$method->getName().'()';
        }
    }

    expect($classNames)->not->toBeEmpty(
        'The production PSR-4 map resolved to no classes at all, so this guard could not have seen mutatable code even if it existed.'
    );

    expect($mutatable !== [] && $flagIsPresent)->toBeFalse(sprintf(
        'The "mutate" script in composer.json still carries --%s, but production code now declares %d mutatable method(s): %s. '
        .'That flag exists only to excuse a package with no mutants at all; remove it so the score of 100 is enforced against real ones.',
        IgnoreMinScoreOnZeroMutationsOption::ARGUMENT,
        count($mutatable),
        $mutatable === [] ? '(none)' : implode(', ', $mutatable),
    ));
});

/*
 * The mutation score floor may not go missing, and may not go down.
 *
 * `composer mutate` carries the floor as a command-line argument because Pest 4
 * exposes no configuration file for the mutate command, so the script is the
 * only place it can live. Nothing else in the toolchain restates it, and its
 * absence is not visible: Tester\MutationTestRunner short-circuits on
 * `$minScore === null` and returns true, so a run that prints uncovered
 * mutants and a score of 0.00% still exits 0. Removing the floor therefore
 * fails green — the one direction this package refuses to fail in, on the one
 * argument carrying the constraint that the floor is never lowered below 100.
 *
 * Its two neighbours in that script already fail loudly. Drop --path=src and
 * the plugin refuses to run at all; drop the zero-mutation escape hatch and
 * the guard above notices. The floor was the only argument with nothing
 * holding it.
 *
 * Spelling and value are both read through the plugin, never restated here:
 *   - MinScoreOption::match() decides which argument in the script is the
 *     floor, so an upstream rename turns this guard red instead of letting a
 *     literal quietly stop matching;
 *   - the value is then read back through MinScoreOption::inputOption() and a
 *     Symfony ArgvInput, which is exactly how
 *     Pest\Mutate\Support\Configuration\CliConfiguration reads it.
 *
 * Deriving the match also settles which value forms count. match() recognises
 * only the --min=<value> form; a space-separated "--min 100" matches nothing,
 * so CliConfiguration never puts the option into its InputDefinition and the
 * floor stays null while looking present to a reader. Borrowing the plugin's
 * own matcher makes this guard accept exactly the spellings the plugin
 * honours, rather than being more generous than the tool it guards.
 *
 * Only 100 passes. A guard asserting merely that some floor is present would
 * pass on --min=0, and one asserting a lower bound would pass on the very
 * lowering the constraint forbids.
 *
 * It reads only composer.json, which is tracked and ships in every dist, and
 * nothing a gate run produces. It is green on a fresh clone.
 */
it('holds the mutation score floor in composer.json at exactly 100', function (): void {
    $manifestPath = ProductionClasses::root().'/composer.json';
    $manifest = ProductionClasses::manifest();

    $scripts = $manifest['scripts'] ?? null;

    if (! is_array($scripts) || ! array_key_exists('mutate', $scripts)) {
        throw new RuntimeException(sprintf('%s declares no "mutate" script.', $manifestPath));
    }

    $mutate = $scripts['mutate'];
    $commands = is_array($mutate) ? $mutate : [$mutate];
    $floorArguments = [];

    foreach ($commands as $command) {
        if (! is_string($command)) {
            throw new RuntimeException('The "mutate" script is not a string or a list of strings.');
        }

        foreach (explode(' ', str_replace(["\t", "\r", "\n"], ' ', $command)) as $argument) {
            if (MinScoreOption::match($argument)) {
                $floorArguments[] = $argument;
            }
        }
    }

    expect($floorArguments === [])->toBeFalse(sprintf(
        'The "mutate" script in composer.json carries no --%1$s argument that %2$s recognises, so the run has no mutation score floor at all. '
        .'Pest returns success unconditionally when the floor is null, so the gate would print uncovered mutants and still exit 0. '
        .'Restore --%1$s=100 — and note that a space-separated "--%1$s 100" is not recognised by the plugin either.',
        MinScoreOption::ARGUMENT,
        MinScoreOption::class,
    ));

    $input = new ArgvInput(
        ['vendor/bin/pest', ...$floorArguments],
        new InputDefinition([MinScoreOption::inputOption()]),
    );

    $declared = $input->getOption(MinScoreOption::ARGUMENT);

    if (! is_string($declared)) {
        throw new RuntimeException(sprintf(
            'The --%s argument in the "mutate" script carries no value this guard can read.',
            MinScoreOption::ARGUMENT,
        ));
    }

    expect((float) $declared)->toBe(100.0, sprintf(
        'The "mutate" script in composer.json sets --%s=%s. The mutation score floor is 100 and may not be lowered.',
        MinScoreOption::ARGUMENT,
        $declared,
    ));
});
