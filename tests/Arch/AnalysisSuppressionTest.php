<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;

/*
 * Static analysis is only a guard while it is still analysing.
 *
 * Five things can switch it off without switching anything red, and none of
 * them is visible in a gate run's output: lower the level and the run passes
 * because it stopped asking; add a baseline and it passes because it agreed to
 * forgive what it already found; add an inline ignore and it passes because it
 * was told to skip the one line that mattered; narrow `paths`, or exclude a
 * directory from them, and it passes because it never opened the file; turn
 * `reportUnmatchedIgnoredErrors` off and every ignore already written stops
 * being able to expire. All five exit 0, print a green "No errors" and leave
 * the package analysed at whatever is left.
 *
 * The fourth is the one this package created the opportunity for the day
 * `config` joined the analysed paths, and it is the loudest-looking and
 * quietest-behaving of the five: narrowing `paths` to `tests` alone was
 * measured to exit 0 with "No errors" and to leave the whole suite green,
 * because until now nothing read that key back. So the analysed set is
 * asserted, and it is derived — from the manifest's own PSR-4 targets and from
 * the provider's own configuration file — rather than listed, because a list
 * written here would be a second copy of the answer and would drift.
 *
 * The fifth is one line and it is the load-bearing one for everything below:
 * an identified suppression is self-expiring only while an unmatched ignore is
 * still an error. Switch that off and every identifier-and-reason check in this
 * file keeps passing over suppressions that have become permanent exemptions.
 *
 * All but the inline ignore are configuration and are asserted here directly.
 * The ignore is the one that needs a policy rather than a prohibition, because
 * it has a legitimate use — and this package has one.
 *
 * ## The policy
 *
 * A suppression is permitted where the analyser is wrong about a framework
 * behaviour. It must name the identifier it suppresses, must carry a one-line
 * reason, and must never substitute for a fix.
 *
 * Naming the identifier is what makes a suppression self-expiring: PHPStan
 * treats an unmatched ignore as a non-ignorable error, so a suppression that
 * names `catch.neverThrown` fails the day the analyser stops reporting
 * `catch.neverThrown` there, and has to be deleted. The two line-wide forms of
 * the same directive — the ones suffixed `-next-line` and `-line`, spelled that
 * way here because writing either in full inside a comment *is* a suppression
 * and PHPStan rightly rejects one that suppresses nothing — name no identifier
 * at all: they suppress every error on that line, including errors that had not
 * been written when the ignore was, and they cannot expire because there is
 * nothing for them to stop matching.
 *
 * The reason is what makes the next reader able to tell the two apart. A
 * suppression whose justification is not written down is indistinguishable, six
 * months later, from one somebody added to make a run go green.
 *
 * **The policy binds wherever the analyser runs, and the sweep is derived from
 * the same key that decides where that is.** `phpstan.neon.dist` names three
 * directories in `paths` — `src`, `tests` and `config` — and an ignore in any of
 * them is honoured by the analyser in full. That sentence was written here once
 * before over a sweep of two autoload maps, which covered two of the three: a
 * line-wide, reasonless directive appended to `config/ameriabank-vpos.php` was
 * measured green under the expectation named for binding wherever the analyser
 * runs. The claim and the mechanism had different sources of truth, and the
 * mechanism agreed with the claim only by coincidence.
 *
 * So the subject list is now `paths` itself, walked with
 * `ProductionClasses::phpFilesUnder()`, and a fourth analysed directory joins
 * the sweep without anybody editing this file. `src` is the one exception and it
 * is reached by `ProductionClasses::files()` instead, because reflecting the
 * production classes back to their paths carries a guarantee a walk does not:
 * every shipped file is either swept or refused, since a `.php` file under a
 * PSR-4 root that declares no matching class stops `all()` rather than being
 * skipped. `config/ameriabank-vpos.php` is exactly such a file — it returns an
 * array and declares nothing — which is why it has to arrive by the walk and
 * cannot arrive through `files()`.
 *
 * The scoped version of this guard left the more dangerous halves unheld: a
 * level-10 finding in a *new test* is what tempts somebody to reach for a
 * directive, and this task's predecessors fixed three such findings rather than
 * suppressing them precisely because that is the standard; while the realistic
 * suppression in `config/` is one silencing Larastan about `env()`'s return
 * type, which is the exact shortcut this policy exists to bound.
 *
 * ## Why this is not an arch() expectation
 *
 * Both halves relate code to phpstan.neon.dist and to a *comment*.
 * pest-plugin-arch expresses relationships between symbols; there is no
 * expectation that can read a configuration file, and none that can see a
 * comment at all. There is therefore no native expectation being duplicated
 * here, and the standing prohibition on hand-rolling reflection guards where
 * arch() covers the ground does not apply.
 *
 * These read only phpstan.neon.dist, composer.json and the directories that
 * file points at — src/, tests/ and config/ — all tracked, and nothing a gate
 * run produces. They are green on a fresh clone.
 */

/**
 * The configuration file PHPStan would actually load, and its text.
 *
 * PHPStan resolves its configuration in a fixed order — phpstan.neon, then
 * phpstan.neon.dist, then phpstan.dist.neon — and stops at the first that
 * exists. A guard that reads the `.dist` while the analyser reads an
 * uncommitted local override would be asserting the level of a file nothing
 * runs, so the two higher-priority names are refused outright rather than
 * merely ignored: this package has one analysis configuration and it is the
 * tracked one.
 *
 * @return array{path: string, source: string}
 */
function analysisConfiguration(): array
{
    $root = ProductionClasses::root();
    $overrides = ['phpstan.neon', 'phpstan.dist.neon'];

    foreach ($overrides as $override) {
        if (file_exists($root.'/'.$override)) {
            throw new RuntimeException(sprintf(
                '%s exists and takes precedence over phpstan.neon.dist, so `composer stan` is not running the tracked configuration and this guard would be reporting on a file nothing uses.',
                $override,
            ));
        }
    }

    $path = $root.'/phpstan.neon.dist';
    $source = file_get_contents($path);

    if ($source === false) {
        throw new RuntimeException(sprintf('Unable to read %s.', $path));
    }

    return ['path' => $path, 'source' => $source];
}

/**
 * The directories the package's own declarations say must be analysed.
 *
 * Derived, never listed. The manifest names where production and test code
 * live, by naming the directories its two PSR-4 maps resolve against; the
 * declared service provider names where the packaged configuration lives, by
 * being the thing that loads it. A directory written into this test instead
 * would be a second copy of an answer the package already states, and the
 * failure mode of a second copy is that it goes on passing after the first one
 * changes.
 *
 * `config` is here for the reason it was added to `paths` at all: it ships, it
 * is the one file permitted to read the environment, and `env()` returns
 * `mixed`, so every value the provider consumes originates in a file nothing
 * else type-checks.
 *
 * @return list<string>
 */
function directoriesRequiringAnalysis(): array
{
    $manifest = ProductionClasses::manifest();
    $required = [
        ...psr4Directories($manifest, 'autoload'),
        ...psr4Directories($manifest, 'autoload-dev'),
        ...packagedConfigurationDirectories($manifest),
    ];

    $required = array_values(array_unique($required));
    sort($required);

    return $required;
}

/**
 * The directories one of composer.json's PSR-4 maps resolves against.
 *
 * Separated from its caller because the production half is needed on its own:
 * `analysedFiles()` reaches `src/` by reflection rather than by walking it, and
 * it has to know which of the analysed directories that applies to without
 * being told.
 *
 * @param  array<array-key, mixed>  $manifest
 * @return list<string>
 */
function psr4Directories(array $manifest, string $section): array
{
    $declared = $manifest[$section] ?? null;

    if (! is_array($declared)) {
        throw new RuntimeException(sprintf(
            'composer.json declares no "%s" section, so this guard cannot tell which directories hold code.',
            $section,
        ));
    }

    /*
     * Only PSR-4 is understood, exactly as in ProductionClasses::all(). Code
     * arriving through another mechanism would be invisible here, and invisible
     * in the direction that passes, so it refuses to run instead.
     */
    $unsupported = array_diff(array_keys($declared), ['psr-4']);

    if ($unsupported !== []) {
        throw new RuntimeException(sprintf(
            'composer.json autoloads through "%s" in its "%s" section, which this guard cannot resolve to a directory. Teach it that mechanism rather than letting code become invisible to it.',
            implode('", "', array_map(static fn (int|string $key): string => (string) $key, $unsupported)),
            $section,
        ));
    }

    $psr4 = $declared['psr-4'] ?? null;

    if (! is_array($psr4) || $psr4 === []) {
        throw new RuntimeException(sprintf('composer.json\'s "%s" section declares no PSR-4 map.', $section));
    }

    $directories = [];

    foreach ($psr4 as $directory) {
        if (! is_string($directory)) {
            throw new RuntimeException(sprintf('composer.json\'s "%s" PSR-4 map points at something that is not a path.', $section));
        }

        $directories[] = trim($directory, '/');
    }

    return $directories;
}

/**
 * The directories `phpstan.neon.dist` actually points the analyser at.
 *
 * Read back rather than assumed, and read back in one place: the expectation
 * that requires this package's directories to be among them and the sweep that
 * visits them are then two questions about the same value, which is the whole
 * point of the fix that put this function here.
 *
 * The block form is the only one this package writes and the only one read
 * back. A `paths:` written as an inline list would leave the sequence
 * unreadable here, and an unreadable configuration is refused rather than
 * treated as an empty one — an empty one would make every requirement below
 * unmet, which is loud, but it would report the wrong reason.
 *
 * @return list<string>
 */
function analysedDirectories(): array
{
    ['path' => $path, 'source' => $source] = analysisConfiguration();

    if (preg_match('/^[ \t]*paths:[ \t]*$\n((?:[ \t]*-[ \t]*\S+[ \t]*$\n?)+)/m', $source, $block) !== 1) {
        throw new RuntimeException(sprintf(
            '%s declares no readable "paths" sequence, so nothing here can say what PHPStan analyses.',
            $path,
        ));
    }

    if (preg_match_all('/^[ \t]*-[ \t]*(\S+)[ \t]*$/m', $block[1], $items) === false) {
        throw new RuntimeException(sprintf('Unable to read the analysed paths out of %s.', $path));
    }

    return array_map(
        static fn (string $item): string => trim($item, "'\"/"),
        $items[1],
    );
}

/**
 * Where the declared service providers load their packaged configuration from,
 * asked of the providers rather than assumed.
 *
 * The provider is the one thing that knows: it merges that file at register()
 * and publishes it at boot(), and it composes the path itself. Reading the
 * path back off the provider means this guard requires the directory PHPStan
 * must analyse to be the directory the package actually loads, rather than a
 * directory that merely has the conventional name.
 *
 * It is asked without an application, so nothing here boots Laravel: the
 * method composes a path out of its own location and a class constant and
 * touches no container. A provider that stops naming a configuration file is a
 * refusal rather than a silent empty result — the requirement to analyse that
 * directory would be dropped by the same edit that made it invisible.
 *
 * @param  array<array-key, mixed>  $manifest
 * @return list<string>
 */
function packagedConfigurationDirectories(array $manifest): array
{
    $extra = $manifest['extra'] ?? null;
    $laravel = is_array($extra) ? $extra['laravel'] ?? null : null;
    $providers = is_array($laravel) ? $laravel['providers'] ?? null : null;

    if (! is_array($providers) || $providers === []) {
        throw new RuntimeException('composer.json declares no Laravel service provider, so this guard cannot ask one where the packaged configuration lives.');
    }

    $root = realpath(ProductionClasses::root());

    if ($root === false) {
        throw new RuntimeException('The repository root does not resolve to a real path.');
    }

    $directories = [];

    foreach ($providers as $provider) {
        if (! is_string($provider) || ! class_exists($provider)) {
            throw new RuntimeException('composer.json declares a service provider for package discovery that does not autoload.');
        }

        $reflection = new ReflectionClass($provider);

        if (! $reflection->hasMethod('configPath')) {
            continue;
        }

        $path = $reflection->getMethod('configPath')->invoke($reflection->newInstanceWithoutConstructor());

        if (! is_string($path)) {
            throw new RuntimeException(sprintf('%s::configPath() does not name a path.', $provider));
        }

        $directory = realpath(dirname($path));

        if ($directory === false || ! str_starts_with($directory, $root.'/')) {
            throw new RuntimeException(sprintf(
                '%s loads its configuration from %s, which is not a directory inside this package.',
                $provider,
                dirname($path),
            ));
        }

        $directories[] = substr($directory, strlen($root) + 1);
    }

    if ($directories === []) {
        throw new RuntimeException('No declared service provider names a packaged configuration file. If this package has stopped shipping one, this guard is asserting a directory that no longer exists and needs revisiting; if it has not, the provider has stopped saying so.');
    }

    return $directories;
}

it('analyses at level 10 with no baseline', function (): void {
    ['path' => $path, 'source' => $source] = analysisConfiguration();

    /*
     * The level is read out of the file rather than assumed, and the value it
     * is compared against is the constraint itself: 10 is PHPStan's maximum
     * and the level this package declares. A guard asserting merely that some
     * level is configured would pass on level 0, which is the shape the
     * weakening actually takes — nobody deletes the key, they lower the digit.
     */
    $found = preg_match_all('/^\s*level:\s*(\S+)\s*$/m', $source, $matches);

    if ($found === false) {
        throw new RuntimeException(sprintf('Unable to scan %s for its analysis level.', $path));
    }

    expect($matches[1])->toHaveCount(1, sprintf(
        '%s declares %d analysis level(s); exactly one is expected. With none, PHPStan falls back to a level this package never chose.',
        $path,
        count($matches[1]),
    ));

    foreach ($matches[1] as $level) {
        expect($level)->toBe('10', sprintf(
            '%s analyses at level %s. The level is 10 — PHPStan\'s maximum — and may not be lowered.',
            $path,
            $level,
        ));
    }

    /*
     * A baseline is the wholesale form of the inline suppression below: it
     * forgives every error present on the day it was written, and it does so
     * without a reason, without an identifier at the site, and without anything
     * at the site at all. Both halves are asserted — the file must not exist,
     * and the configuration must not name one — because either alone leaves the
     * other route open, and an `includes:` pointing at a baseline that has not
     * been generated yet is a commit that only fails on somebody else's machine.
     */
    $baselines = glob(ProductionClasses::root().'/*baseline*');

    if ($baselines === false) {
        throw new RuntimeException('Unable to scan the repository root for a PHPStan baseline.');
    }

    expect($baselines)->toBe([], sprintf(
        "The repository contains a PHPStan baseline:\n%s\n"
        .'A baseline forgives every error it was generated from, permanently and without a stated reason. This package has none and adds none; errors are fixed or suppressed one at a time with an identifier and a reason.',
        implode("\n", $baselines),
    ));

    /*
     * The two configuration keys that switch analysis off wholesale, collected
     * and compared as a list rather than asserted with expect()->not->toContain().
     *
     * toContain() is variadic — every argument after the first is another
     * needle, not a failure message — so `not->toContain($key, $why)` reads
     * like an assertion with an explanation and behaves like an assertion about
     * two needles, and it does not fail when the file contains the first one.
     * It was written that way here first, and it passed against a
     * phpstan.neon.dist carrying a live `ignoreErrors` section. That is the
     * failure direction this whole file exists to refuse, found only because
     * the guard was made to fail by hand before it was accepted.
     */
    $keys = [
        'includes:' => 'Nothing in this package\'s analysis configuration is included from elsewhere, and a baseline is what an includes section is normally added to reach.',
        'ignoreErrors' => 'That suppresses errors by pattern across every file that matches. A suppression belongs at the one line it is about, naming the identifier it suppresses and carrying the reason it exists.',
        'excludePaths' => 'That removes files from the analysed set without removing them from the package, which is the same silence as narrowing paths and is harder to notice because paths still names the directory.',
        'reportUnmatchedIgnoredErrors' => 'PHPStan reports an unmatched ignore as an error of its own, and that is the entire mechanism by which an identified suppression expires. The key is absent because its default is the behaviour this package depends on; setting it either way is a decision to have an opinion about self-expiry, and only one direction of that opinion is available here.',
    ];

    $present = [];

    foreach ($keys as $key => $why) {
        if (str_contains($source, $key)) {
            $present[] = sprintf('%s carries a "%s" section. %s', $path, $key, $why);
        }
    }

    expect($present)->toBe([], implode("\n", $present));
});

it('analyses every directory this package ships or tests', function (): void {
    ['path' => $path] = analysisConfiguration();

    /*
     * The level is what a weakening is expected to look like, and `paths` is
     * what one actually looks like. Narrowing it does not lower any declared
     * standard, does not appear in a diff as a number going down, and does not
     * change a single line of output: the run still says level 10, still says
     * "No errors", and simply never opens the files it stopped being pointed
     * at. Measured on this package, `paths` narrowed to `tests` alone exits 0
     * with the whole suite green.
     *
     * Coverage rather than equality. A directory added to `paths` is somebody
     * analysing more, which needs no permission from this guard; a directory
     * missing from it is the thing being refused.
     */
    $required = directoriesRequiringAnalysis();

    expect($required)->not->toBeEmpty(
        'This package\'s own declarations resolved to no analysable directory at all, so this guard could not have seen a narrowed path even if one existed.'
    );

    $analysed = analysedDirectories();
    $missing = [];

    foreach ($required as $directory) {
        if (! in_array($directory, $analysed, true)) {
            $missing[] = $directory;
        }
    }

    expect($missing)->toBe([], sprintf(
        "%s analyses %s, and this package's own declarations require %s.\n"
        .'Not analysed: %s. '
        .'A directory this package ships or tests and does not analyse is unanalysed at level 10 exactly as if there were no analyser, while every gate still reports level 10 and no errors. '
        .'The set required here is derived — the manifest\'s PSR-4 targets, and the directory the declared provider loads its configuration from — so a new one joins it without anybody editing this test.',
        $path,
        $analysed === [] ? 'nothing' : implode(', ', $analysed),
        implode(', ', $required),
        $missing === [] ? '(none)' : implode(', ', $missing),
    ));
});

/**
 * Every file the analyser is pointed at, from the key that points it there.
 *
 * `phpstan.neon.dist`'s own `paths` is the subject list, so the sweep's reach
 * and the analyser's reach are the same value rather than two lists that agree
 * today. The sibling expectation above requires this package's own directories
 * to be among them; this one visits whatever is there, including a directory
 * nobody has added yet.
 *
 * **`src/` is reached by reflection and everything else by a walk**, and the
 * asymmetry is the reflection guarantee rather than a preference.
 * `ProductionClasses::files()` bridges classes back to paths, which is exact for
 * `src/` because every file there declares a class and `all()` refuses one that
 * does not — so a shipped file is either swept or loudly refused, never quietly
 * skipped. Nothing outside `src/` has that property: a Pest test file declares
 * no class at all, and `config/ameriabank-vpos.php` returns an array and
 * declares nothing, so both would make `all()` throw if it were pointed at them
 * and both must arrive by `phpFilesUnder()` instead.
 *
 * `src/` is therefore swept whether or not `paths` still names it. That is the
 * safe direction and it costs nothing: a suppression in shipped code is a defect
 * whichever directories the analyser is currently pointed at, and `paths`
 * dropping a directory this package ships is what the sibling expectation above
 * is for — it goes red rather than this sweep going quiet.
 *
 * Every root is required to yield at least one file, separately. A sweep that
 * silently lost a root would go on reporting no violations, which is the
 * failure direction this whole file refuses — and it is the direction the
 * previous version of this function actually failed in, sweeping two autoload
 * maps while the prose above it claimed the analyser's whole reach.
 *
 * @return list<string>
 *
 * @throws JsonException when composer.json is not valid JSON
 */
function analysedFiles(): array
{
    $production = ProductionClasses::files();

    expect($production)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen a suppression even if one existed.'
    );

    $root = ProductionClasses::root();
    $reflected = psr4Directories(ProductionClasses::manifest(), 'autoload');
    $files = $production;

    foreach (analysedDirectories() as $directory) {
        if (in_array($directory, $reflected, true)) {
            continue;
        }

        $walked = ProductionClasses::phpFilesUnder($root.'/'.$directory);

        expect($walked)->not->toBeEmpty(sprintf(
            'phpstan.neon.dist analyses %s and it holds no PHP file at all, so this sweep could not have seen a '
            .'suppression there even if one existed. A directory that is analysed and holds nothing is either a '
            .'stale path or a sweep that has lost a root; both are silent, and both leave a level-10 finding one '
            .'directive away from being permanently forgiven.',
            $directory,
        ));

        $files = [...$files, ...$walked];
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

it('bounds every inline analysis suppression wherever the analyser runs', function (): void {
    $files = analysedFiles();

    $violations = [];
    $sites = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        /*
         * Which lines are comment lines is decided by the tokeniser, not by
         * the text: a suppression written into a string literal is not a
         * suppression, and the prose above this test names the directives it
         * forbids and must not be read as using them.
         */
        $commentLines = [];

        foreach (PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $span = substr_count($token->text, "\n");

            for ($offset = 0; $offset <= $span; $offset++) {
                $commentLines[$token->line + $offset] = true;
            }
        }

        $lines = explode("\n", $source);

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (! isset($commentLines[$number]) || ! str_contains($line, '@phpstan-ignore')) {
                continue;
            }

            $site = sprintf('%s:%d', $file, $number);
            $sites[] = $site;

            /*
             * The identifier form. -next-line and -line suppress everything on
             * a line and can never stop matching, so they are refused whatever
             * reason accompanies them.
             */
            if (preg_match('/@phpstan-ignore-(next-line|line)\b/', $line) === 1) {
                $violations[] = $site.' — uses a line-wide directive, which names no identifier and can never expire';

                continue;
            }

            if (preg_match('/@phpstan-ignore\s+([a-zA-Z0-9_]+\.[a-zA-Z0-9_.]+(?:\s*,\s*[a-zA-Z0-9_]+\.[a-zA-Z0-9_.]+)*)/', $line) !== 1) {
                $violations[] = $site.' — names no PHPStan error identifier, so nothing tells the analyser what was wrong or tells this package when it stops being wrong';

                continue;
            }

            /*
             * The reason. PHPStan's own `identifier (comment)` form counts, and
             * so does the line immediately above — which is where a reason long
             * enough to be worth reading ends up, its last line sitting
             * directly on top of the directive.
             *
             * Immediately above, and not the nearest non-blank comment line
             * further up. This guard walked upwards through blank ` *` spacers
             * at first, and that accepted a suppression appended to the end of
             * an existing docblock: the spacer was skipped and the method's own
             * summary line was read as the justification. Every method in src/
             * carries a docblock, so the reason clause was satisfied by default
             * at every site a suppression is likely to be added — the clause
             * held nothing exactly where it was needed. Adjacency is what makes
             * the reason a reason *for this directive* rather than whatever
             * prose happens to be above it.
             */
            $hasInlineReason = preg_match('/@phpstan-ignore\s+[^(]*\(\s*\S/', $line) === 1;
            $hasPrecedingReason = false;
            $above = $number - 1;

            if ($above >= 1 && isset($commentLines[$above])) {
                $text = trim(preg_replace('#^\s*(//+|/\*+|\*+/?|\#)#', '', $lines[$above - 1]) ?? '');

                $hasPrecedingReason = $text !== '' && ! str_contains($text, '@phpstan-ignore');
            }

            if (! $hasInlineReason && ! $hasPrecedingReason) {
                $violations[] = $site.' — carries no reason, so nothing distinguishes it from a suppression added to make a run go green';
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "An inline analysis suppression in code this package analyses is unbounded:\n%s\n\n"
        .'A suppression is permitted where the analyser is wrong about a framework behaviour. It must name the identifier it suppresses, must carry a one-line reason, and must never substitute for a fix. '
        .'That binds everywhere phpstan.neon.dist points, which is tests/ as well as src/. '
        ."The %d suppression site(s) this guard found were:\n%s",
        implode("\n", $violations),
        count($sites),
        $sites === [] ? '(none)' : implode("\n", $sites),
    ));
});
