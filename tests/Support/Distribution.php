<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use RuntimeException;

use function array_filter;
use function array_values;
use function escapeshellarg;
use function exec;
use function implode;
use function is_dir;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * What a consumer of this package actually receives.
 *
 * Composer installs a dist archive, and that archive is not the repository:
 * `.gitattributes` marks whole directories `export-ignore` and git drops them.
 * The distinction is load-bearing here — `/tests` and `/.github` are meant to
 * be dropped and `config/` is meant to survive — and nothing in the suite could
 * see it until this class existed.
 *
 * ## The archive is produced by git, not modelled
 *
 * `git archive` is the command Composer's own dist builder is a wrapper around,
 * and it is the only thing that knows how `export-ignore` composes: an
 * attribute on a *directory* removes everything beneath it, while
 * `git check-attr` asked about a file inside that directory answers
 * `unspecified` — measured, and the reason a check built on `check-attr` alone
 * would have reported `tests/TestCase.php` as shipping. Re-implementing that
 * would be a second model of the tool, wrong in whichever direction nobody
 * looked.
 *
 * ## `--worktree-attributes`, and why that flag is the whole design
 *
 * The failure being guarded is *an edit to `.gitattributes`* — a future sweep
 * adding `/config`, which silently breaks config publishing for every consumer
 * while every local gate stays green. Plain `git archive HEAD` reads the
 * attributes committed at HEAD, so it is green on the run that should stop the
 * edit and only goes red once the edit is already committed. `--worktree-
 * attributes` takes the attributes from the working tree instead, so the guard
 * fails while the change is still being written, which is the only moment the
 * feedback is cheap.
 *
 * What that does **not** change is where the *content* comes from: still HEAD.
 * A file created and not yet committed is absent from this archive, which is
 * correct — an uncommitted file is not in any dist — but it means this class
 * cannot be used to assert that every file under `src/` ships. It would report
 * every new file as missing until it is staged, which is noise rather than a
 * finding. What it is used for is the other direction, and the presence of the
 * one file whose absence is silent.
 *
 * ## The three questions, and why they are one class
 *
 * What ships is decided by `.gitattributes`; what is *in* the repository is
 * decided by the index; what is deliberately kept out of it is decided by the
 * ignore configuration. All three are answers only git has, all three are read
 * through the one command runner below, and a guard that modelled any of them
 * in PHP would be a second implementation wrong in whichever direction nobody
 * looked. That is the whole membership rule for this class.
 *
 * ## What this depends on
 *
 * `git` and `tar`, and a `.git` directory. All three are present for any
 * contributor with a clone and in CI, which checks out with git; and `tests/`
 * is export-ignored, so nobody installing this package ever runs it. Task 001's
 * own release criterion is `git archive HEAD | tar -t`, so both binaries were
 * already the established mechanism — what is new is that a machine checks it
 * rather than a person at release time.
 *
 * A missing `.git` stops the run with a sentence rather than skipping. A guard
 * that silently does not run reads as protection, which is worse than no guard.
 */
final class Distribution
{
    /**
     * The archive listing, held for the life of the process.
     *
     * Building the archive costs a `git archive` and a `tar`, and the mutation
     * gate re-runs the tests that reach here once per mutant on the service
     * provider. Nothing in this suite edits `.gitattributes` or commits, so the
     * answer cannot change within a run; a demonstration that edits the
     * attributes runs in its own process and sees its own answer.
     *
     * @var list<string>|null
     */
    private static ?array $entries = null;

    /**
     * Every file a consumer receives, as a repository-relative path.
     *
     * Directory entries are dropped: the tar format lists them, and they are
     * noise for every question asked of this list.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $root = ProductionClasses::root();

        if (! is_dir($root.'/.git')) {
            throw new RuntimeException(sprintf(
                '%s is not a git checkout, so what this package distributes cannot be read. The distribution is '
                .'produced by git archive from .gitattributes, and there is nothing else to ask.',
                $root,
            ));
        }

        self::run(sprintf('git -C %s rev-parse --verify HEAD', escapeshellarg($root)));

        $archive = tempnam(sys_get_temp_dir(), 'vpos-dist-');

        if ($archive === false) {
            throw new RuntimeException('Unable to create a temporary file to write the distribution archive into.');
        }

        try {
            self::run(sprintf(
                'git -C %s archive --worktree-attributes --format=tar -o %s HEAD',
                escapeshellarg($root),
                escapeshellarg($archive),
            ));

            $listing = self::run(sprintf('tar -tf %s', escapeshellarg($archive)));
        } finally {
            unlink($archive);
        }

        return self::$entries = array_values(array_filter(
            $listing,
            static fn (string $entry): bool => $entry !== '' && ! str_ends_with($entry, '/'),
        ));
    }

    /**
     * Every path committed at HEAD, whether or not it ships.
     *
     * The other half of the comparison: a path has to be *in* the repository
     * before "does it ship?" is a question, and a rule about what must not ship
     * needs its subjects from somewhere other than the archive it is asserting
     * about.
     *
     * @return list<string>
     */
    public static function committed(): array
    {
        $root = ProductionClasses::root();

        return self::run(sprintf('git -C %s ls-tree -r --name-only HEAD', escapeshellarg($root)));
    }

    /**
     * Every path that is tracked now, plus every path the next `git add -A`
     * would start tracking.
     *
     * The union, not `ls-files` alone, and the reason is the same one
     * `--worktree-attributes` is used for above: the interesting moment is
     * while the change is still being written. A rule about tracked files that
     * takes its subjects from the index is green on exactly the file that has
     * just broken it, and only goes red once the commit has happened — which
     * for a rule about what a published repository contains is one commit too
     * late. `--others --exclude-standard` adds the working tree minus
     * everything the ignore configuration excludes, which is precisely the set
     * that is about to become tracked.
     *
     * Ignored paths are therefore absent by construction rather than by a
     * filter written here, and a path that stops being ignored joins the
     * subjects of every guard built on this without anybody editing one.
     *
     * @return list<string>
     */
    public static function wouldBeTracked(): array
    {
        $root = ProductionClasses::root();

        return self::run(sprintf(
            'git -C %s ls-files --cached --others --exclude-standard',
            escapeshellarg($root),
        ));
    }

    /**
     * Where the ignore configuration says $path is excluded, asked of git.
     *
     * Two answers in one call, and both are load-bearing. That git ignores the
     * path at all is the premise of any rule about it — a rule forbidding
     * pointers into a tree the reader does not have means nothing once the
     * reader does have it — so a path git does not ignore is a refusal here
     * rather than an empty result. And the file and line git names as the
     * source of the exclusion is what lets a guard permit that one line
     * without permitting the file it sits in: an ignore entry is the mechanism
     * that makes a path untracked, not a pointer to it.
     *
     * `--no-index` because these paths are, by the premise, not in the index.
     *
     * @return array{file: string, line: int}
     */
    public static function ignoreSource(string $path): array
    {
        $root = ProductionClasses::root();
        $output = [];
        $status = 0;

        exec(
            sprintf(
                'git -C %s check-ignore -v --no-index -- %s 2>&1',
                escapeshellarg($root),
                escapeshellarg($path),
            ),
            $output,
            $status,
        );

        if ($status === 1) {
            throw new RuntimeException(sprintf(
                'git does not ignore %s, so it is either tracked or about to be. A guard that forbids pointing '
                .'at it rests on nobody being able to follow the pointer, and that has stopped being true — '
                .'settle what the rule is now before this refusal is silenced.',
                $path,
            ));
        }

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                "Asking git whether %s is ignored exited %d:\n%s",
                $path,
                $status,
                implode("\n", $output),
            ));
        }

        if (preg_match('/^(.+):(\d+):/U', $output[0] ?? '', $matched) !== 1) {
            throw new RuntimeException(sprintf(
                "git reports %s as ignored but named no source for the rule:\n%s",
                $path,
                implode("\n", $output),
            ));
        }

        return ['file' => $matched[1], 'line' => (int) $matched[2]];
    }

    /**
     * Runs a command and returns its output, or refuses with everything it saw.
     *
     * The exit status is captured from the command itself rather than read
     * through a pipeline, where it would report the last stage's status and
     * pass silently in the direction that looks green. Standard error is
     * redirected into the same buffer so a failure message carries git's own
     * words rather than an exit code on its own.
     *
     * @return list<string>
     */
    private static function run(string $command): array
    {
        $output = [];
        $status = 0;

        exec($command.' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new RuntimeException(sprintf(
                "`%s` exited %d:\n%s",
                $command,
                $status,
                implode("\n", $output),
            ));
        }

        return array_values(array_filter(
            $output,
            static fn (string $line): bool => $line !== '',
        ));
    }
}
