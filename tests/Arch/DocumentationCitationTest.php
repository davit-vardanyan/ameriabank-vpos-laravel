<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\Distribution;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionSource;
use DavitVardanyan\AmeriabankVpos\Vpos as VposClient;

/*
 * A doc comment in src/ may not cite a path that does not exist.
 *
 * The prose in this package's production classes is unusually load-bearing: it
 * records why a decision was made, and it points at the guard that holds it.
 * `Vpos`'s facade docblock names `tests/Arch/FacadeContractTest.php` so a
 * reader knows the transcribed `@method` tags are checked rather than
 * remembered; `ConfigurationException` names
 * `tests/Arch/ExceptionFactorySignatureTest.php` for the same reason. Those
 * citations are worth having — a rule whose enforcement a reader cannot find
 * reads as an aspiration.
 *
 * ## Why they need holding
 *
 * A citation is a claim about the repository, and nothing checked it. Rename a
 * guard and the docblock quietly points at nothing; move a file and every
 * pointer to it is stale in a file nobody rereads. The core package paid a
 * whole task for exactly this shape: 531 citations of an instructions file that
 * had become untracked, discovered at publication, none of which any gate had
 * ever looked at.
 *
 * There is a second edge here that the core did not have. `.gitattributes`
 * export-ignores `/tests`, so a merchant reading the shipped dist finds
 * `tests/Arch/FacadeContractTest.php` cited and no `tests/` directory to look
 * in. **That is decided and allowed**: the citation is verifiable from the
 * repository, which is public, and telling a reader where the check lives is
 * worth more than the mild dead end of it not being in their vendor directory.
 * What is not allowed is a citation that is dead *everywhere* — and that is
 * what this file makes impossible.
 *
 * ## What counts as a citation
 *
 * A path: at least one directory separator, and a file extension from a fixed
 * vocabulary. Both requirements do work.
 *
 * The separator excludes a bare filename. `ConfigurationException` cites
 * `RedactedRequestException.php` mid-sentence, continuing from a full path a
 * few words earlier; a bare name states no location, so resolving it would mean
 * searching for it, and a guard that searches will find something eventually
 * and call it a match. The extension excludes a package name — `php-http/discovery`
 * has a separator and is not a path.
 *
 * URLs are stripped before matching, because the host and path of a link look
 * exactly like a relative path once the scheme is removed.
 *
 * ## Two roots, and why the second one is necessary rather than convenient
 *
 * A citation resolves if it exists under **this repository's root or the core
 * client's**. The core's root is derived by walking up from the file the core's
 * own `Vpos` class is loaded from until a `composer.json` appears, so it
 * follows the installed package rather than a path written here.
 *
 * The second root is not a loosening for its own sake. `ConfigurationException`
 * records a measurement against the core at a stated version and cites
 * `src/Http/RedactedNetworkException.php` — a path in the core's tree that
 * looks repository-relative and is not. Refusing it would force the prose to
 * drop the evidence, and evidence is the whole reason that paragraph is worth
 * more than an assertion.
 *
 * The cost is stated rather than hidden: a citation meant as repository-relative
 * that happens to exist in the core's tree is accepted. `src/` and `README.md`
 * exist in both, so this guard cannot tell those two apart — it catches a path
 * that exists in neither, which is what "dangling" means.
 *
 * ## What is deliberately not checked
 *
 * The line number after a path. `RedactedNetworkException.php:59` is checked as
 * far as the file; whether line 59 still says what the citation claims is not
 * something a filesystem can answer, and asserting only that the file is long
 * enough would go red on every unrelated edit above it while proving nothing.
 *
 * ## Mutation testing cannot see this
 *
 * The expectation asserts an absence — no dangling citation — and mutants
 * remove and invert code rather than adding a wrong path to a comment. So no
 * mutation score will ever report on it, and the demonstration recorded against
 * it is the only evidence it works: one docblock pointed at a file that does
 * not exist, this guard seen red, and the file restored from a checksummed
 * snapshot.
 */

/**
 * The trees a citation in `src/` may resolve against.
 *
 * @return array<string, string>
 */
function citationRoots(): array
{
    $file = (new ReflectionClass(VposClient::class))->getFileName();

    if ($file === false) {
        throw new RuntimeException(sprintf(
            '%s is autoloaded from no file, so the core package\'s root cannot be located and a citation into '
            .'its tree could not be told apart from a dangling one.',
            VposClient::class,
        ));
    }

    $directory = dirname($file);

    while (! is_file($directory.'/composer.json')) {
        $parent = dirname($directory);

        if ($parent === $directory) {
            throw new RuntimeException(sprintf(
                'Walking up from %s found no composer.json, so the core package\'s root cannot be located.',
                $file,
            ));
        }

        $directory = $parent;
    }

    return [
        'this repository' => ProductionClasses::root(),
        'the core client package' => $directory,
    ];
}

it('cites no path that does not exist', function (): void {
    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen a dangling '
        .'citation even if one existed.'
    );

    $roots = citationRoots();
    $citations = [];

    foreach ($files as $file) {
        foreach (ProductionSource::docComments($file) as $comment) {
            $prose = preg_replace('#https?://\S+#', ' ', $comment['text']);

            if (! is_string($prose)) {
                throw new RuntimeException(sprintf('Unable to strip links out of the doc comment at %s:%d.', $file, $comment['line']));
            }

            if (preg_match_all('#(?:[A-Za-z0-9_.-]+/)+[A-Za-z0-9_.-]+\.(?:php|md|json|xml|dist|ya?ml|neon|lock|txt)\b#', $prose, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $path) {
                $citations[] = ['file' => $file, 'line' => $comment['line'], 'path' => $path];
            }
        }
    }

    expect($citations)->not->toBeEmpty(
        'No doc comment in src/ cites a path at all. Several do — the facade points at the guard that checks '
        .'its tags, and the exception class points at the guard that checks its factories — so this sweep has '
        .'stopped recognising a citation, and it would pass whatever those comments said.'
    );

    $dangling = [];

    foreach ($citations as $citation) {
        $found = false;

        foreach ($roots as $root) {
            if (file_exists($root.'/'.$citation['path'])) {
                $found = true;

                break;
            }
        }

        if (! $found) {
            $dangling[] = sprintf('%s:%d cites %s', $citation['file'], $citation['line'], $citation['path']);
        }
    }

    expect($dangling)->toBe([], sprintf(
        "A doc comment in src/ cites a path that exists in neither %s:\n%s\n"
        .'A citation is a claim about the repository, and a stale one sends a reader looking for a guard, a '
        .'configuration file or a piece of evidence that is not there — which reads as though the claim beside '
        .'it were never checked either. Fix the path, or say in prose what the file used to hold.',
        implode(' nor ', array_keys($roots)),
        implode("\n", $dangling),
    ));
});

/*
 * A tracked file may not point at what git keeps out of the repository.
 *
 * The expectation above asks whether a cited path exists. This one asks
 * whether the reader will have it — a different question, and for two paths
 * the answers differ.
 *
 * This repository keeps its operating instructions and its working notes on
 * disk and out of git, deliberately and from its first commit. Nothing that is
 * not committed reaches a clone, a dist or the code host, so a line in a
 * tracked file that sends a reader to one of them sends them nowhere, and
 * states a reason they cannot check against the document it came from. The
 * core package measured what that costs: 531 such pointers inside shipped
 * files, found at publication rather than by any gate, and a test that read one
 * of those paths at test time and was therefore red on every fresh clone.
 *
 * ## Subjects: what would be tracked, not what is
 *
 * `Distribution::wouldBeTracked()` — the index, plus every working-tree file
 * the ignore configuration does not exclude. The alternative, the committed
 * tree, is wrong here and this file is itself the evidence: the pointer that
 * made this guard necessary was written into a file that was not yet
 * committed, so a HEAD-derived sweep would have been green on it and would
 * have gone red only after the commit it exists to prevent. Untracked and
 * not ignored is what "about to be tracked" means, and it is the last moment
 * the feedback is cheap. It is the same choice `Distribution` makes with
 * `--worktree-attributes`, for the same reason.
 *
 * ## What is exempt, and why neither exemption is a filename
 *
 * Two forms, both derived at test time:
 *
 *   1. **The line that does the excluding.** `git check-ignore -v` names the
 *      file and the line number of the rule that keeps each path out, and that
 *      one line is permitted. An ignore entry is not a pointer at a path — it
 *      is the mechanism that makes the path unreachable, and the string is
 *      configuration git parses rather than prose a human follows. The
 *      permission is the single line git names, not the file it sits in: a
 *      sentence about those paths written three lines above it is still a
 *      violation.
 *   2. **A string literal equal to the name itself, in the position a name is
 *      given one.** This rule has to be able to say what it forbids, and so
 *      does anything that borrows it. A `T_CONSTANT_ENCAPSED_STRING` whose
 *      value is exactly an excluded path *and* which is being bound to a name —
 *      the right-hand side of an assignment or a constant declaration, with no
 *      call opened between the `=` and the literal — is this rule's vocabulary.
 *      Anything else is a pointer, whatever its length.
 *
 * Neither names a file. The ignore configuration is permitted for what it is
 * rather than for what it is called, and stays permitted if it is renamed,
 * split, or joined by a second one.
 *
 * ## Why the exemption is positional, and what it does not reach
 *
 * It was a value claim first — a literal equal to an excluded name, anywhere —
 * and the paragraph above it said the read half of the rule fell out of the
 * same clause, because reading one of those files needs a literal longer than
 * its name. That is true of the excluded directory and false of the excluded
 * file: the file sits at the repository root, so a `file_get_contents()` of it
 * from the root is the exact string the exemption permitted, character for
 * character. Measured, in a file this suite already sweeps: such a read
 * appended to `tests/TestCase.php`, and the guard passed. The earlier
 * demonstration used a path under the excluded directory, which is longer than
 * the vocabulary and was correctly reported, so the spelling that mattered had
 * never been exercised. It is the second of the two defects the core package
 * found at publication — a test that read its instructions file and was red on
 * every fresh clone.
 *
 * **The alternative was to reject the exemption inside a call to a file
 * function, and it was rejected on the enumeration.** That guard would have to
 * know which functions read a file, and PHP offers nothing to ask: there is no
 * reflection on what a function does, so the set would be a list of names
 * written here — `file_get_contents`, `fopen`, `file`, `readfile`,
 * `SplFileObject`, whatever a framework adds next — and every name missing from
 * it would be a silent pass. A hand-maintained subject list whose omissions are
 * green is the exact mechanism every guard in this suite is built to avoid.
 * The position a literal occupies is in the token stream already, needs no list
 * of anything, and makes "this rule's vocabulary" a structural claim rather
 * than a claim about a value.
 *
 * **What it does not reach, stated as a property rather than as a list.** Two
 * gaps have been found in this clause. One is closed, one is not, and the
 * difference between them is structural rather than a matter of how hard
 * anybody looked — which is why what follows is a boundary and not an
 * enumeration. The first version of this paragraph named one gap and read as
 * though it named all of them, in the same revision that was correcting another
 * sentence for claiming a limit it did not have.
 *
 * *Closed, and it was a spelling.* PHP's four keyword reads take no parenthesis,
 * so the `(` clause never ended a binding for them and an assignment from an
 * `include` of the excluded file sat inside this rule's own vocabulary.
 * Measured: that read appended to a file this sweep already visits, and the
 * guard passed. It is closed in the token stream, by the four keyword tokens
 * ending a binding exactly as `(` does — see `vocabularyLines()` below, and note
 * that four tokeniser constants are not the list of function names the paragraph
 * above rejects.
 *
 * *Open, and no clause built on literals can close it.* A literal bound to a
 * local and then read through that variable is exempt at the binding and
 * invisible at the read, because the read carries no literal for a token sweep
 * to see. That is not one spelling among several: it is the whole of what a
 * sweep of literals cannot see, and it is worth stating as the property rather
 * than as an instance — **this clause sees a read only where the read spells the
 * name.** A name put into a variable, a constant, a concatenation or any
 * computed string is on the other side of that line, wherever it is then used.
 *
 * What catches the open one is the failure itself: the file is not in a clone,
 * so the read is red on every fresh checkout and in CI — loud, immediate, and
 * the reason the core found its copy at all. The clause below closes the
 * spellings somebody writes by accident; the clone closes the one somebody works
 * at.
 *
 * ## The vocabulary is two literals, and that is flagged rather than hidden
 *
 * The subject list is derived; the two names are not, and cannot be. Deriving
 * them from the excluded tree would mean reading it, which is the other half
 * of the same prohibition. Deriving them from the ignore configuration would
 * mean choosing two of its nine entries, and the property that separates them
 * from the rest — that no command produces them — is stated nowhere a test can
 * read.
 *
 * The obvious generalisation was tried and measured before being rejected.
 * Forbidding a citation of *any* ignored path, with the candidates found by
 * the regex above and classified by `git check-ignore`, flags four legitimate
 * citations in this repository: `composer.json` names the coverage report its
 * own script writes, `phpunit.xml.dist` names the autoloader Composer
 * generates, `README.md` explains why the lock file is not committed, and two
 * arch guards read Composer's installed-package manifest. Each of those is a
 * path some documented command produces, which is what makes naming it useful.
 * The general rule would therefore need four exceptions, and four exceptions
 * are a worse hand-maintained list than two nouns.
 *
 * The two nouns are not taken on trust either: each is put to git before it is
 * used, and a name git does not ignore is a refusal rather than a pass. That
 * is what stops the vocabulary from quietly ceasing to match anything, and it
 * is why a mangled name fails loudly instead of emptying the sweep.
 *
 * ## Mutation testing cannot see this either
 *
 * Another absence assertion, and the mutation gate is scoped to `src` besides.
 * The evidence is by hand and recorded: a pointer at the excluded directory
 * inserted into a docblock, this guard seen red; a pointer at the excluded
 * file inserted likewise, seen red again; a `file_get_contents()` of the
 * excluded file, red; an `include` of it and a `require` of a path under the
 * excluded directory, both red once the keyword tokens end a binding and both
 * green before; and this rule's own vocabulary — the two names bound to a local
 * in `excludedWorkingPaths()` — green throughout, which is what says the
 * exemption still exempts. Each insertion reverted from a checksummed snapshot
 * and seen green again.
 */

/**
 * The paths this repository keeps out of git, each with the line of ignore
 * configuration that puts it there.
 *
 * The directory form carries its trailing separator, which is what makes it a
 * path rather than a word: everything the rule is about lives *under* it, and
 * every reference to something under it spells the separator. It is also the
 * form the ignore configuration itself uses.
 *
 * @return array<string, array{file: string, line: int}>
 */
function excludedWorkingPaths(): array
{
    $names = ['CLAUDE.md', '.claude/'];
    $excluded = [];

    foreach ($names as $name) {
        $excluded[$name] = Distribution::ignoreSource($name);
    }

    return $excluded;
}

/**
 * The lines of a PHP source on which one of $names is *given* as a name, and
 * which of them.
 *
 * The tokeniser decides, not the text: a comment mentioning the same characters
 * yields no literal here and is not exempted. What the tokeniser is asked is
 * two questions rather than one — what the literal's value is, and where it
 * sits — because the value alone permitted a `file_get_contents()` of the
 * excluded file, which is a read spelled exactly like the vocabulary.
 *
 * A literal is being bound to a name when the nearest preceding `=` is an
 * assignment to a variable, a property or a constant, and no call has been
 * opened since it. Three token facts make that cheap and exact:
 *
 * - every compound assignment and every comparison has its own token, so a bare
 *   `=` is an assignment and nothing else;
 * - `[` and `]` are not boundaries, so a literal inside an array being assigned
 *   is still being bound — which is the shape this rule's own vocabulary takes
 *   in `excludedWorkingPaths()` above;
 * - `(` is, so `$text = file_get_contents($name);` is a read and not a binding,
 *   even though it is an assignment;
 * - and so are `T_INCLUDE`, `T_INCLUDE_ONCE`, `T_REQUIRE` and `T_REQUIRE_ONCE`,
 *   which are the reads PHP spells as keywords rather than as calls and which
 *   therefore need no parenthesis for the clause above to end on. **Those are
 *   four tokeniser constants the language defines — a closed set nobody can
 *   extend — and not four function names somebody wrote down, which is why they
 *   are not the enumeration rejected in the section above.**
 *
 * A statement boundary or a brace ends the binding. An interpolated expression
 * opens with a brace token of its own, so a literal inside one is reported
 * rather than exempted — the conservative direction.
 *
 * @param  list<string>  $names
 * @return array<int, array<string, true>>
 */
function vocabularyLines(string $source, array $names): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));

    $lines = [];
    $binding = false;
    $callDepth = 0;

    foreach ($tokens as $index => $token) {
        if (in_array($token->text, [';', '{', '}'], true) || $token->is(T_CURLY_OPEN)) {
            $binding = false;
            $callDepth = 0;

            continue;
        }

        if ($token->text === '(') {
            $callDepth++;

            continue;
        }

        if ($token->text === ')' && $callDepth > 0) {
            $callDepth--;

            continue;
        }

        // The four reads PHP spells as keywords. They open a read with no
        // parenthesis, so the clause above never ends a binding for them and
        // an assignment from one would otherwise look exactly like a binding.
        if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            $binding = false;

            continue;
        }

        if ($token->text === '=') {
            $previous = $tokens[$index - 1] ?? null;

            // A variable, a property or a constant name. Anything else in that
            // position is not PHP this guard needs to understand.
            $binding = $previous !== null && ($previous->is(T_VARIABLE) || $previous->is(T_STRING));
            $callDepth = 0;

            continue;
        }

        if (! $binding || $callDepth !== 0 || ! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            continue;
        }

        $value = substr($token->text, 1, -1);

        if (in_array($value, $names, true)) {
            $lines[$token->line][$value] = true;
        }
    }

    return $lines;
}

it('points no tracked file at what git keeps out of the repository', function (): void {
    $files = Distribution::wouldBeTracked();

    expect($files)->not->toBeEmpty(
        'git reports nothing tracked and nothing about to be, which cannot be true of this repository — so this '
        .'sweep has no subjects and would pass whatever any of them said.'
    );

    $excluded = excludedWorkingPaths();
    $names = array_keys($excluded);
    $root = ProductionClasses::root();

    $pointers = [];
    $occurrences = 0;

    foreach ($files as $relative) {
        $path = $root.'/'.$relative;
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s, so it could not be swept.', $path));
        }

        $vocabulary = str_ends_with($relative, '.php') ? vocabularyLines($source, $names) : [];
        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            foreach ($excluded as $name => $origin) {
                if (! str_contains($line, $name)) {
                    continue;
                }

                $occurrences++;

                if ($origin['file'] === $relative && $origin['line'] === $number) {
                    continue;
                }

                if (isset($vocabulary[$number][$name])) {
                    continue;
                }

                $pointers[] = sprintf('%s:%d cites %s', $relative, $number, $name);
            }
        }
    }

    expect($occurrences > 0)->toBeTrue(
        'This sweep matched nothing anywhere, including the ignore configuration that necessarily names what it '
        .'excludes. It has stopped recognising what it is looking for, and would report no violation whatever '
        .'any file said.'
    );

    expect($pointers)->toBe([], sprintf(
        "A file that is tracked, or about to be, points at a path git keeps out of this repository:\n%s\n"
        .'Those paths exist on the machine that wrote the line and nowhere else — not in a clone, not in the '
        .'dist, not on the code host — so the pointer resolves for its author and for no reader, and the claim '
        .'beside it cannot be checked against the document it came from. Say in the file what the pointer was '
        .'going to say, or cite something the repository ships: the analysis configuration, the manifest, a '
        ."guard under tests/. %d occurrence(s) were permitted, being ignore entries and this rule's own "
        .'vocabulary.',
        implode("\n", $pointers),
        $occurrences - count($pointers),
    ));
});
