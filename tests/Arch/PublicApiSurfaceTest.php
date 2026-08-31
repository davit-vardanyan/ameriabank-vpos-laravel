<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionSource;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

/*
 * What this package marks internal is exactly what it does not hand anybody.
 *
 * ## What the marking does, and why it is not free
 *
 * It is not a comment. PhpStorm greys the symbol out at every call site outside
 * this package and warns on use; PHPStan's `internal` rules report the same;
 * `roave/backward-compatibility-check` treats a marked symbol as exempt from
 * semantic versioning, so a breaking change to it is not reported as one.
 *
 * Both errors therefore cost something, and they cost different things:
 *
 *   - **marked, but really public** — a merchant is told not to use the only
 *     supported way to do something, *and* gets no protection when it changes.
 *     The second half is invisible until a release breaks somebody;
 *   - **unmarked, but really internal** — the package silently promises
 *     semantic versioning on wiring it never meant to support, and an IDE
 *     recommends it to a merchant as though it were the documented route.
 *
 * `BackUrlResolver` is the recorded instance of the first. It carried the
 * marking, and unpicking that took a whole design decision: the core's
 * `InitPaymentRequest` takes `backUrl` as a required constructor argument, so a
 * merchant told the resolver was not for them passed `route(...)` instead —
 * which left `ameriabank-vpos.back_url` read by no code path a payment
 * executes. The key was inert for real traffic and load-bearing only for
 * `vpos:check`, and the two values could drift apart silently. What the marking
 * bought was that; what it cost was the only supported way to build the value,
 * withheld from the people who needed it.
 *
 * ## Why this guard is no longer a blanket ban
 *
 * It was one, deliberately, as a decision point: *"a future symbol that
 * genuinely should be internal makes this red, and that is the intent."* That
 * happened. `ConfigReader` exists so the service provider and `vpos:check`
 * cannot disagree about a configuration key, nothing outside this package
 * should read configuration through it, and its shape is free to change
 * whenever those two callers need it to. It is marked, and the ban went red as
 * designed.
 *
 * A ban with `ConfigReader` written into an exception list would be the defect
 * this suite exists to prevent — a hand-maintained list that stays green
 * through exactly the change it is supposed to catch. So the placeholder is
 * replaced by the invariant it was standing in for, and the invariant is
 * derived.
 *
 * ## The invariant, and where each half of it comes from
 *
 * **A production class is marked internal if and only if this package hands it
 * to nobody.** Four things constitute handing it over, and every one of them is
 * read at test time from the thing that actually does the handing:
 *
 *   1. **Package discovery.** `extra.laravel.providers` and
 *      `extra.laravel.aliases` in composer.json are what Composer writes into
 *      `vendor/composer/installed.json` and what `PackageManifest` registers.
 *      A class named there is registered into every installing application
 *      before the merchant writes a line.
 *   2. **A container binding.** `Container::getBindings()` under Testbench,
 *      intersected with this package's own classes. This is the distinction
 *      the provider's own docblock already draws about `BackUrlResolver`:
 *      writing the binding out *"is what makes it a documented seam rather
 *      than an accident of the class being autowirable"*. Autowiring can
 *      construct almost anything; a declared binding is a promise that a
 *      constructor-injected parameter of that type will resolve. Task 005 made
 *      `BackUrlResolver` public API by giving it one, so binding-as-publication
 *      is a distinction this package has already made on the record.
 *   3. **An artisan command.** Resolved from the console kernel, so what is
 *      read is the registration the provider actually performed. A command is
 *      run by name from a merchant's terminal and named in their deploy
 *      scripts.
 *   4. **Being throwable.** An exception crosses the package boundary by being
 *      raised, and the merchant has to name it in a catch block. Telling them
 *      not to use the class they are obliged to catch, and exempting it from
 *      semantic versioning while it is the documented failure contract, is the
 *      first error above in its purest form.
 *
 * Everything else — a class the package constructs for itself and never hands
 * over — is wiring, and says so.
 *
 * ## Both halves fail, and both were demonstrated
 *
 * Marking something the package hands over is red; leaving something it hands
 * to nobody unmarked is red; and *removing the binding that makes a class
 * public* is red, which is the property that makes this derived rather than
 * decorative. All three were executed by hand and reverted from a checksummed
 * snapshot.
 *
 * ## Mutation testing cannot see any of this
 *
 * Mutants remove and invert code; they never add an annotation. Every
 * expectation here asserts a correspondence between an annotation and a
 * registration, so no mutation score will ever report on it and the
 * demonstrations above are the only evidence that will ever exist.
 *
 * ## Why this file boots an application when the rest of tests/Arch does not
 *
 * Two of the four sources are registrations rather than declarations, and a
 * registration is only observable once something performs it. Reading the
 * provider's source for `bind(` calls would be a second implementation of the
 * container, green whenever the two disagreed. `tests/Pest.php` binds the base
 * case to the Feature suite only, so this file binds it for itself.
 */

uses(TestCase::class);

/**
 * Every production class this package hands to somebody, and what hands it.
 *
 * Keyed by class name so a failure can say *why* a class is public rather than
 * merely asserting that it is; a reader who disagrees with the verdict then has
 * the registration to argue with.
 *
 * @return array<class-string, string>
 */
function publicApiSurface(): array
{
    $production = ProductionClasses::all();
    $surface = [];

    $extra = ProductionClasses::manifest()['extra'] ?? [];
    $discovery = is_array($extra) ? ($extra['laravel'] ?? []) : [];

    if (! is_array($discovery)) {
        throw new RuntimeException('composer.json\'s "extra.laravel" is not an object, so package discovery cannot be read.');
    }

    foreach (['providers', 'aliases'] as $section) {
        $declared = $discovery[$section] ?? [];

        if (! is_array($declared)) {
            throw new RuntimeException(sprintf('composer.json\'s "extra.laravel.%s" is not a list or object.', $section));
        }

        foreach ($declared as $entry) {
            if (is_string($entry) && in_array($entry, $production, true)) {
                $surface[$entry] = sprintf('composer.json names it in extra.laravel.%s, so package discovery registers it into every installing application', $section);
            }
        }
    }

    foreach (array_keys(app()->getBindings()) as $key) {
        if (is_string($key) && in_array($key, $production, true)) {
            $surface[$key] = 'the service provider binds it in the container, which is a promise that a constructor-injected parameter of that type resolves';
        }
    }

    foreach (app()->make(Kernel::class)->all() as $name => $command) {
        if (! is_object($command)) {
            throw new RuntimeException(sprintf('The console kernel holds something that is not a command under `%s`.', $name));
        }

        $class = $command::class;

        if (in_array($class, $production, true)) {
            $surface[$class] = sprintf('the service provider registers it with artisan as `%s`, which a merchant runs by name', $name);
        }
    }

    foreach ($production as $class) {
        if (is_a($class, Throwable::class, true)) {
            $surface[$class] = 'it is throwable, so it crosses the package boundary by being raised and a merchant has to name it in a catch block';
        }
    }

    return $surface;
}

/**
 * Whether a class's own doc comment carries the internal marking.
 *
 * @param  class-string  $class
 */
function isMarkedInternal(string $class): bool
{
    $docComment = (new ReflectionClass($class))->getDocComment();

    return $docComment !== false && str_contains($docComment, '@internal');
}

it('hands a consumer nothing it marks internal', function (): void {
    $production = ProductionClasses::all();

    expect($production)->not->toBeEmpty(
        'The production PSR-4 map resolved to no classes at all, so this guard could not have seen a wrongly '
        .'marked one even if it existed.'
    );

    $surface = publicApiSurface();

    expect($surface)->not->toBeEmpty(
        'Nothing in this package resolved as public API, which cannot be true of a package that ships a service '
        .'provider — so the four sources this guard reads are not being read, and every marking below would '
        .'pass whatever it said.'
    );

    $wrong = [];

    foreach ($production as $class) {
        if (isset($surface[$class]) && isMarkedInternal($class)) {
            $wrong[] = sprintf('%s is marked internal, but %s', $class, $surface[$class]);
        }
    }

    expect($wrong)->toBe([], sprintf(
        "This package marks a symbol internal and then hands it over anyway:\n%s\n"
        .'The marking is not a comment: an IDE greys the symbol out at every call site outside this package, '
        .'PHPStan reports its use, and a backward-compatibility check exempts it from semantic versioning — so '
        .'a symbol that is really public and carries the marking gets merchants told not to use it and no '
        .'protection when it changes. BackUrlResolver carried it, and the result was a configuration key no '
        .'payment path read. Either remove the marking, or stop handing the class over.',
        implode("\n", $wrong),
    ));
});

it('marks internal everything it hands to nobody', function (): void {
    $production = ProductionClasses::all();
    $surface = publicApiSurface();

    $unmarked = [];

    foreach ($production as $class) {
        if (! isset($surface[$class]) && ! isMarkedInternal($class)) {
            $unmarked[] = $class;
        }
    }

    expect($unmarked)->toBe([], sprintf(
        "This package ships a class it hands to nobody and does not say is internal:\n%s\n"
        .'Nothing registers it through package discovery, nothing binds it in the container, artisan does not '
        .'know it, and it is not throwable — so no merchant can obtain it except by constructing it themselves '
        .'against a shape this package is free to change. Unmarked, the package promises semantic versioning on '
        .'it and an IDE offers it as a supported route. Either hand it over deliberately — bind it, register it, '
        .'document it — or mark it @internal.',
        implode("\n", $unmarked),
    ));
});

it('decides the internal marking on the class it applies to', function (): void {
    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen a marking even '
        .'if one existed.'
    );

    $classDocComments = [];

    foreach (ProductionClasses::all() as $class) {
        $reflection = new ReflectionClass($class);
        $docComment = $reflection->getDocComment();

        if ($docComment !== false && str_contains($docComment, '@internal')) {
            $classDocComments[] = $docComment;
        }
    }

    $stray = [];

    foreach ($files as $file) {
        foreach (ProductionSource::docComments($file) as $comment) {
            if (! str_contains($comment['text'], '@internal')) {
                continue;
            }

            if (in_array($comment['text'], $classDocComments, true)) {
                continue;
            }

            $stray[] = sprintf('%s:%d', $file, $comment['line']);
        }
    }

    expect($stray)->toBe([], sprintf(
        "A doc comment marks something internal that is not a class:\n%s\n"
        .'The two expectations above decide the marking per class, against what the package registers, binds, '
        .'runs as a command or raises. A marking on a method, a constant or a property is outside that '
        .'invariant: it says a member of an otherwise public class is not supported, which nothing here checks '
        .'and nothing here permits. Either move the symbol into a class that is internal as a whole, or widen '
        .'this guard to state what makes a member internal — and argue for it, rather than typing it.',
        implode("\n", $stray),
    ));
});
