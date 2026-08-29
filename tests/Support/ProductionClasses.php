<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use const JSON_THROW_ON_ERROR;

use JsonException;
use ReflectionClass;
use RuntimeException;

use function array_diff;
use function array_keys;
use function array_map;
use function array_pop;
use function class_exists;
use function dirname;
use function file_get_contents;
use function implode;
use function interface_exists;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function rtrim;
use function scandir;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use function trait_exists;

/**
 * Everything this package ships, discovered rather than listed.
 *
 * A guard that compares production code against the build manifest needs both
 * sides to come from a source of truth at test time. The manifest side is a
 * `json_decode()` of `composer.json`; this is the other side — the classes the
 * package actually autoloads, found by walking the same manifest's PSR-4 map
 * over the filesystem.
 *
 * The alternative is a list of class names written into a test, which stops
 * being true the moment somebody adds a class and stays green while doing so.
 * That is the failure mode these guards exist to prevent, so it is not the
 * mechanism they are built on.
 *
 * It reads only `composer.json` and `src/`, both of which are tracked and ship
 * in every dist, and nothing a gate run produces. It is green on a fresh clone.
 */
final class ProductionClasses
{
    /**
     * Every class, interface and trait the production autoloader can reach.
     *
     * Sorted, so a guard comparing derived sets against manifest sets has a
     * stable order to compare in.
     *
     * @return list<class-string>
     *
     * @throws JsonException when composer.json is not valid JSON
     */
    public static function all(): array
    {
        $manifest = self::manifest();
        $autoload = $manifest['autoload'] ?? null;

        if (! is_array($autoload)) {
            throw new RuntimeException('composer.json declares no "autoload" section.');
        }

        /*
         * Only PSR-4 is walked. If production code ever arrives through
         * another autoload mechanism this would go blind to it silently, so it
         * refuses to run instead.
         */
        $unsupported = array_diff(array_keys($autoload), ['psr-4']);

        if ($unsupported !== []) {
            throw new RuntimeException(sprintf(
                'composer.json autoloads production code through "%s", which this helper cannot walk. Teach it that mechanism rather than letting production code become invisible to it.',
                implode('", "', array_map(static fn (int|string $key): string => (string) $key, $unsupported)),
            ));
        }

        $psr4 = $autoload['psr-4'] ?? null;

        if (! is_array($psr4) || $psr4 === []) {
            throw new RuntimeException('composer.json declares no production PSR-4 map.');
        }

        $classNames = [];

        foreach ($psr4 as $prefix => $directory) {
            if (! is_string($prefix) || ! is_string($directory)) {
                throw new RuntimeException('The production PSR-4 map is not a string-to-string mapping.');
            }

            foreach (self::classesUnder(self::root().'/'.rtrim($directory, '/'), $prefix) as $className) {
                $classNames[] = $className;
            }
        }

        sort($classNames);

        $resolved = [];

        foreach ($classNames as $className) {
            if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                throw new RuntimeException(sprintf(
                    '%s does not autoload; the PSR-4 map and the filesystem disagree.',
                    $className,
                ));
            }

            $resolved[] = $className;
        }

        return $resolved;
    }

    /**
     * The concrete production classes that extend $parent.
     *
     * Abstract classes are excluded: package discovery instantiates what it is
     * given, so an abstract base is never a discovery subject.
     *
     * @param  class-string  $parent
     * @return list<class-string>
     *
     * @throws JsonException when composer.json is not valid JSON
     */
    public static function extending(string $parent): array
    {
        $found = [];

        foreach (self::all() as $className) {
            if (! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf($parent)) {
                continue;
            }

            $found[] = $className;
        }

        return $found;
    }

    /**
     * The repository root, located from this file rather than from a working
     * directory a test runner is free to change.
     */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * composer.json, decoded.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException when composer.json is not valid JSON
     */
    public static function manifest(): array
    {
        $path = self::root().'/composer.json';
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $path));
        }

        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException(sprintf('%s does not decode to an object.', $path));
        }

        return $manifest;
    }

    /**
     * Every `.php` file under $base, expressed as a PSR-4 class name.
     *
     * @return list<class-string>
     */
    private static function classesUnder(string $base, string $prefix): array
    {
        if (! is_dir($base)) {
            throw new RuntimeException(sprintf('The PSR-4 map points at %s, which is not a directory.', $base));
        }

        $classNames = [];
        $pending = [$base];

        while ($pending !== []) {
            $current = array_pop($pending);
            $entries = scandir($current);

            if ($entries === false) {
                throw new RuntimeException(sprintf('Unable to list %s.', $current));
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current.'/'.$entry;

                if (is_dir($path)) {
                    $pending[] = $path;

                    continue;
                }

                if (! str_ends_with($path, '.php')) {
                    continue;
                }

                $relative = substr($path, strlen($base) + 1, -4);

                /** @var class-string $className */
                $className = $prefix.str_replace('/', '\\', $relative);
                $classNames[] = $className;
            }
        }

        return $classNames;
    }
}
