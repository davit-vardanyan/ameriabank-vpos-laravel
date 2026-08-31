<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use JsonException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

use function class_exists;
use function is_subclass_of;
use function ksort;
use function sprintf;

/**
 * Every named factory on every exception class this package ships.
 *
 * The exception classes are the whole surface a merchant, a log and a bug
 * report see this package through, and two guards ask questions of that
 * surface: one about what a factory's parameters may be, and one about what
 * the trace of a live refusal carries. Both need the same subject list, and a
 * list written down twice is a list that drifts — so it is derived once, here.
 *
 * Derived from `ProductionClasses`, which walks composer.json's own PSR-4 map:
 * a ninth factory, or a second exception class, joins these guards the moment
 * it is autoloadable, without anybody remembering to add it.
 *
 * A *public static method declared on the class itself* is what a factory is
 * here. That is the client package's convention and this one's: the
 * constructor is private, so the static surface is the entire set of things
 * the class can ever be asked to build. Inherited statics are excluded because
 * they belong to whoever declared them.
 */
final class ExceptionFactories
{
    /**
     * Keyed `Fully\Qualified\Class::factory`, so a guard's table can be keyed
     * the same way and a missing row names itself.
     *
     * @return array<string, ReflectionMethod>
     *
     * @throws JsonException when composer.json is not valid JSON
     */
    public static function all(): array
    {
        $factories = [];

        foreach (ProductionClasses::all() as $className) {
            if (! class_exists($className) || ! is_subclass_of($className, Throwable::class)) {
                continue;
            }

            foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                /*
                 * Both tests are needed and neither is redundant.
                 * ReflectionClass::getMethods() treats its filter as a union
                 * rather than an intersection, so IS_PUBLIC|IS_STATIC would
                 * return every public method and every static one; and a
                 * method the class inherited is not something this class can
                 * be held responsible for.
                 */
                if (! $method->isStatic() || $method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $factories[$className.'::'.$method->getName()] = $method;
            }
        }

        if ($factories === []) {
            throw new RuntimeException(sprintf(
                'No named factory was found on any %s this package ships, so every guard built on this list '
                .'would have passed without inspecting anything.',
                Throwable::class,
            ));
        }

        ksort($factories);

        return $factories;
    }
}
