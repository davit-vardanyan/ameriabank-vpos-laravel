<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\Distribution;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

/*
 * The package a merchant installs is not the repository, and the difference is
 * checked.
 *
 * `.gitattributes` decides it. `/tests` and `/.github` carry `export-ignore`
 * and are dropped from the dist; `src/`, `config/`, the licence and the three
 * markdown files are not, and survive. Until this file existed nothing in the
 * suite could see either half — the whole gate runs against a working tree that
 * contains everything, so an edit that removed a shipped directory from the
 * dist would pass nine green commands and break every installing application.
 *
 * ## The presence half is the point
 *
 * Task 001 left a release-time criterion for a person to run: the archive must
 * contain `src/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md` and
 * `SECURITY.md`, and no `tests/` or `.github/`. That criterion has two
 * weaknesses this file exists to remove. It is manual, so it is run once per
 * release at best; and `config/` is not in it at all, which is the omission
 * that invites the failure — a future export-ignore sweep adding `/config`
 * removes the file `vendor:publish` copies, and a merchant then publishes
 * nothing and configures a package that reads defaults it cannot see. An
 * absence-only check cannot catch that. Nothing fails, anywhere, until a
 * merchant's deploy.
 *
 * ## What is asserted, and where each subject comes from
 *
 *   1. **Every file the provider publishes ships.** The subject is read from
 *      `ServiceProvider::pathsToPublish()`, which is the registry
 *      `vendor:publish` itself consults — so this asserts the very file the
 *      merchant is told to copy, not a path written down here that could drift
 *      from it. That is why this file boots an application: the publish map is
 *      a registration, and a registration is only observable once something
 *      performs it.
 *   2. **Nothing under a development PSR-4 root ships.** Derived from
 *      composer.json's `autoload-dev.psr-4` — the directories the manifest
 *      itself says are not production.
 *   3. **No committed dotfile or dot-directory ships.** Derived from what is
 *      committed at HEAD, filtered to paths beginning with a dot. This is the
 *      `.github` half of task 001's criterion, expressed as the rule rather
 *      than as a list of the paths that happen to exist today.
 *
 * ## Two boundaries, recorded rather than papered over
 *
 * **Content comes from HEAD; attributes come from the working tree.** The
 * reasoning for that choice is in `Distribution`. The consequence here is that
 * nothing asserts every `src/` file ships: an uncommitted new file is legitimately
 * absent from HEAD, and a guard reporting it as missing would be red on every
 * run between writing a file and committing it. What that leaves uncovered is a
 * file added to `src/` and never committed at all, which `composer test` cannot
 * see for the same reason `git archive` cannot.
 *
 * **The tool configs are not asserted.** `pint.json`, `rector.php`,
 * `phpstan.neon.dist` and `phpunit.xml.dist` are export-ignored and should stay
 * so, but they share no property that composer.json or the filesystem states —
 * they are not dotfiles and not under a PSR-4 root — so the only way to assert
 * them is to write their four names down here. That is the hand-maintained list
 * every other guard in this directory exists to avoid, and it would go stale on
 * the fifth tool. Task 001's release criterion still covers them.
 *
 * ## Mutation testing cannot see any of this
 *
 * Two of the three expectations assert an absence, and the third asserts the
 * presence of a file no mutant can remove — `.gitattributes` is not PHP. So no
 * mutation score will ever report on this file, and the demonstration recorded
 * against it is the only evidence it works: `/config export-ignore` added to
 * `.gitattributes` by hand, this file seen red, and the attributes restored from
 * a checksummed snapshot.
 */

uses(TestCase::class);

/**
 * The files `vendor:publish` copies, as repository-relative paths.
 *
 * @return list<string>
 */
function publishedFiles(): array
{
    $root = ProductionClasses::root();
    $published = [];

    foreach (array_keys(ServiceProvider::pathsToPublish(AmeriabankVposServiceProvider::class)) as $source) {
        $resolved = realpath($source);

        if ($resolved === false) {
            throw new RuntimeException(sprintf(
                'The provider publishes %s, which does not exist. A publish map naming a missing file answers '
                .'`vendor:publish --list` and then copies nothing.',
                $source,
            ));
        }

        if (! str_starts_with($resolved, $root.'/')) {
            throw new RuntimeException(sprintf(
                'The provider publishes %s, which is outside %s, so whether it ships is not a question this '
                .'repository can answer.',
                $resolved,
                $root,
            ));
        }

        $published[] = substr($resolved, strlen($root) + 1);
    }

    return $published;
}

it('ships every file the provider publishes', function (): void {
    $published = publishedFiles();

    expect($published)->not->toBeEmpty(
        'The provider registered no publishable files at all, so this guard has nothing to check and the '
        .'`vendor:publish` tag it documents would copy nothing.'
    );

    $entries = Distribution::entries();

    expect($entries)->not->toBeEmpty(
        'The distribution archive is empty, so every expectation in this file would pass whatever .gitattributes '
        .'said.'
    );

    $missing = [];

    foreach ($published as $path) {
        if (! in_array($path, $entries, true)) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], sprintf(
        "This package publishes a file it does not distribute:\n%s\n"
        .'`vendor:publish --tag=ameriabank-vpos-config` copies that file out of the installed package, so a '
        .'merchant whose dist does not contain it publishes nothing and then configures this package through a '
        .'file that is not there. Check .gitattributes for an export-ignore entry covering it — the whole gate '
        .'stays green when one is added, because every other command reads the working tree, where the file is '
        .'still present.',
        implode("\n", $missing),
    ));
});

it('ships nothing from a development-only source root', function (): void {
    $manifest = ProductionClasses::manifest();
    $autoloadDev = $manifest['autoload-dev'] ?? [];
    $psr4 = is_array($autoloadDev) ? ($autoloadDev['psr-4'] ?? []) : [];

    if (! is_array($psr4) || $psr4 === []) {
        throw new RuntimeException('composer.json declares no autoload-dev PSR-4 map, so this guard has no subjects and cannot report on them.');
    }

    $directories = [];

    foreach ($psr4 as $directory) {
        if (! is_string($directory)) {
            throw new RuntimeException('composer.json\'s autoload-dev PSR-4 map points at something that is not a directory.');
        }

        $directories[] = rtrim($directory, '/').'/';
    }

    $shipped = [];

    foreach (Distribution::entries() as $entry) {
        foreach ($directories as $directory) {
            if (str_starts_with($entry, $directory)) {
                $shipped[] = $entry;
            }
        }
    }

    expect($shipped)->toBe([], sprintf(
        "This package distributes files from a development-only source root:\n%s\n"
        .'composer.json puts those directories in autoload-dev, so they are not production code and no consumer '
        .'has the dev dependencies to run them. They belong behind an export-ignore entry in .gitattributes.',
        implode("\n", $shipped),
    ));
});

it('ships no dotfile or dot-directory', function (): void {
    $committed = array_values(array_filter(
        Distribution::committed(),
        static fn (string $path): bool => str_starts_with($path, '.'),
    ));

    expect($committed)->not->toBeEmpty(
        'Nothing beginning with a dot is committed at HEAD, which cannot be true of a repository that has a '
        .'.gitignore — so this sweep has no subjects and would pass whatever .gitattributes said.'
    );

    $entries = Distribution::entries();
    $shipped = [];

    foreach ($committed as $path) {
        if (in_array($path, $entries, true)) {
            $shipped[] = $path;
        }
    }

    expect($shipped)->toBe([], sprintf(
        "This package distributes a dotfile or dot-directory:\n%s\n"
        .'Repository furniture — CI workflows, editor settings, git configuration — is tracked and not shipped. '
        .'A consumer receives none of it and can do nothing with it, and a shipped workflow file in particular '
        .'reads as though this package expects to run in their pipeline. Add an export-ignore entry in '
        .'.gitattributes.',
        implode("\n", $shipped),
    ));
});
