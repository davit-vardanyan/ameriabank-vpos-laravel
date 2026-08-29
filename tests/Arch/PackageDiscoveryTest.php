<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

/*
 * Package discovery is a promise made in composer.json, and nothing else in
 * this suite can see it broken.
 *
 * An installing application never registers this package's provider by hand:
 * Composer writes extra.laravel.{providers,aliases} into
 * vendor/composer/installed.json, Illuminate\Foundation\PackageManifest reads
 * them, and the framework registers what it finds. Delete the aliases block or
 * repoint the providers entry at a class that does not exist and a merchant's
 * application either loses the facade silently or dies at boot with a
 * class-not-found — while the whole suite stays green.
 *
 * It stays green because the suite never runs discovery. Testbench registers
 * the provider explicitly through TestCase::getPackageProviders(), and every
 * test reaches the facade by its fully-qualified name, so the manifest is never
 * consulted. Nor can that be fixed by exercising the real mechanism here:
 * PackageManifest builds from vendor/composer/installed.json, and the package
 * under test is the root package, which does not appear there. The manifest's
 * *content* is therefore what is guarded, and this comment is the record that
 * its *execution* is not.
 *
 * Both sides derive from a source of truth at test time. The declared side is a
 * json_decode() of composer.json; the expected side is the production classes
 * the PSR-4 map resolves to, filtered by what they actually extend. Neither is
 * a list of class names written down here — a hand-maintained list would stay
 * green through exactly the change these guards exist to catch, which is
 * somebody adding a provider or a facade and not registering it.
 *
 * These read only composer.json and src/, both tracked and shipped, and nothing
 * a gate run produces. They are green on a fresh clone.
 */

/**
 * extra.laravel, in the shape the framework expects to find it.
 *
 * A missing extra.laravel section is a guarded condition and not a broken
 * manifest, so it yields an empty section and lets the expectations below
 * report it. Anything present but malformed stops the run instead, because a
 * guard that cannot read its subject must not report on it.
 *
 * @return array<string, mixed>
 */
function discoverySection(): array
{
    $extra = ProductionClasses::manifest()['extra'] ?? [];

    if (! is_array($extra)) {
        throw new RuntimeException('composer.json\'s "extra" is not an object.');
    }

    $laravel = $extra['laravel'] ?? [];

    if (! is_array($laravel)) {
        throw new RuntimeException('composer.json\'s "extra.laravel" is not an object.');
    }

    /** @var array<string, mixed> */
    return $laravel;
}

it('names every production service provider in composer.json so discovery registers it', function (): void {
    $section = discoverySection();
    $declaredValue = $section['providers'] ?? [];

    if (! is_array($declaredValue)) {
        throw new RuntimeException('composer.json\'s "extra.laravel.providers" is not a list.');
    }

    $declared = [];

    foreach ($declaredValue as $entry) {
        if (! is_string($entry)) {
            throw new RuntimeException('composer.json\'s "extra.laravel.providers" holds something that is not a class name.');
        }

        $declared[] = $entry;
    }

    /*
     * Each declared entry is checked for existence before the sets are
     * compared, so the mutation that matters most — a renamed or mistyped
     * provider — is reported as what it is rather than as a set difference.
     * PackageManifest hands these straight to the container, so a name that
     * does not resolve is a fatal error on the first request after install.
     */
    foreach ($declared as $entry) {
        expect(class_exists($entry))->toBeTrue(sprintf(
            'composer.json declares %s in extra.laravel.providers, but no such class exists. Package discovery hands that name straight to the container, so every application installing this package would die at boot.',
            $entry,
        ));

        expect(is_subclass_of($entry, ServiceProvider::class))->toBeTrue(sprintf(
            'composer.json declares %s in extra.laravel.providers, but it does not extend %s, so the framework cannot register it.',
            $entry,
            ServiceProvider::class,
        ));
    }

    sort($declared);

    $expected = ProductionClasses::extending(ServiceProvider::class);

    expect($declared)->toBe($expected, sprintf(
        'composer.json\'s extra.laravel.providers declares [%s], but src/ ships [%s]. Package discovery registers exactly what the manifest names, so anything missing here is a provider no installing application will ever boot.',
        $declared === [] ? '(nothing)' : implode(', ', $declared),
        $expected === [] ? '(nothing)' : implode(', ', $expected),
    ));
});

it('maps every production facade to a discovery alias in composer.json', function (): void {
    $section = discoverySection();
    $declaredValue = $section['aliases'] ?? [];

    if (! is_array($declaredValue)) {
        throw new RuntimeException('composer.json\'s "extra.laravel.aliases" is not an object.');
    }

    $declared = [];

    foreach ($declaredValue as $alias => $target) {
        if (! is_string($alias) || ! is_string($target)) {
            throw new RuntimeException('composer.json\'s "extra.laravel.aliases" is not a name-to-class-name mapping.');
        }

        $declared[$alias] = $target;
    }

    foreach ($declared as $alias => $target) {
        expect(class_exists($target))->toBeTrue(sprintf(
            'composer.json aliases "%s" to %s in extra.laravel.aliases, but no such class exists. AliasLoader would resolve the alias to a class that cannot be loaded.',
            $alias,
            $target,
        ));

        expect(is_subclass_of($target, Facade::class))->toBeTrue(sprintf(
            'composer.json aliases "%s" to %s in extra.laravel.aliases, but it does not extend %s, so the alias would not behave as a facade.',
            $alias,
            $target,
            Facade::class,
        ));
    }

    /*
     * The alias name is the facade's own short name, which is the convention
     * the framework's first-party packages follow and the only derivation
     * available: a facade declares its container key, never the global name it
     * is published under.
     */
    $expected = [];

    foreach (ProductionClasses::extending(Facade::class) as $facade) {
        $expected[(new ReflectionClass($facade))->getShortName()] = $facade;
    }

    ksort($declared);
    ksort($expected);

    expect($declared)->toBe($expected, sprintf(
        'composer.json\'s extra.laravel.aliases declares [%s], but src/ ships the facade(s) [%s]. Without the manifest entry the alias is never registered, and a merchant following the documented `Vpos::` call site gets a class-not-found instead.',
        $declared === [] ? '(nothing)' : implode(', ', array_map(
            static fn (string $alias, string $target): string => $alias.' => '.$target,
            array_keys($declared),
            array_values($declared),
        )),
        $expected === [] ? '(nothing)' : implode(', ', array_map(
            static fn (string $alias, string $target): string => $alias.' => '.$target,
            array_keys($expected),
            array_values($expected),
        )),
    ));

    /*
     * One literal, deliberately. `Vpos` is the name merchants type, and nothing
     * in the codebase derives it — the equality above would follow a rename of
     * the facade class straight into a renamed alias and stay green while the
     * published name changed underneath the documentation. The set comparison
     * proves the mapping is complete; this proves the published name is still
     * the one that was promised.
     */
    expect(array_key_exists('Vpos', $declared))->toBeTrue(
        'composer.json no longer aliases "Vpos" in extra.laravel.aliases. That is the published name of this package\'s facade; renaming it is a breaking change for every application that calls Vpos::.'
    );
});
