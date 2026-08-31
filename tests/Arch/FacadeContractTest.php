<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use Illuminate\Support\Facades\Facade;

/*
 * A facade's @method static tags are the only signature anything ever sees.
 *
 * Facade::__callStatic() resolves through the container and forwards, so from
 * a static analyser's point of view `Vpos::payments()` has no signature at all
 * — it sees `mixed` from the first arrow onwards, and every type error after it
 * goes unreported, in a package whose subject is money. The tags are what
 * restore that. They are not documentation; they are the contract PHPStan
 * analyses merchant code against.
 *
 * Which makes a *stale* tag worse than a missing one. A missing tag costs
 * analysis: the call resolves to mixed and nothing downstream is checked, which
 * is a blind spot but an honest one. A drifted tag costs correctness: PHPStan
 * trusts what it is given, so it confidently analyses a merchant's call site
 * against a signature the client no longer has, reports no error, and the gate
 * here stays green through exactly that change — because nothing in this
 * package runs the tags against the class they describe.
 *
 * Until this guard, the tags were held by a sentence in the facade's own
 * docblock asking whoever changes the client to remember. The client is in
 * another repository.
 *
 * ## What is derived, and from where
 *
 * Nothing here is written down twice:
 *
 *   - the facades are ProductionClasses::extending(Facade::class), so a second
 *     facade is guarded the day it is added;
 *   - the class each one is checked against is the return of its own
 *     getFacadeAccessor(), reached by reflection because it is protected. The
 *     facade declares what it resolves; naming the client here instead would be
 *     a hand-maintained subject list that keeps passing after the facade is
 *     repointed at something else;
 *   - the methods are ReflectionClass::getMethods(IS_PUBLIC) minus the
 *     constructor, which is not reachable through a facade;
 *   - the tags are parsed out of the facade's own docblock;
 *   - the short names in those tags are resolved through the facade file's own
 *     `use` statements, tokenised from the file reflection points at.
 *
 * That last one is the difference between a guard and a spell-checker. The tag
 * says `PaymentsClient`; reflection says
 * `DavitVardanyan\AmeriabankVpos\Client\PaymentsClient`. Comparing the short
 * names would pass a tag naming a *different* PaymentsClient — a plausible
 * mistake, since this package's namespace nests under the client's and a
 * merchant application may well have one of its own.
 *
 * ## What is compared, and what deliberately is not
 *
 * Both directions of the method set: a tag with no method behind it is the
 * stale case above, and a method with no tag is the blind spot. Then, per
 * method, the return type and the parameter list — name, type, by-reference,
 * variadic, and whether the parameter is optional.
 *
 * A default value's *spelling* is not compared. Reflection reports
 * `Language::English` as a fully-qualified constant name and the tag writes it
 * as the short one, and reconciling those two would be resolving expressions
 * rather than types. Whether a parameter is optional at all is compared, which
 * is the half that changes what a call site may legally omit.
 *
 * **Only native types are compared, and a generic tag cannot currently be
 * written.** `reflectedTypeName()` reads `ReflectionMethod::getReturnType()`
 * and `ReflectionParameter::getType()`, so a drift that lives only in the
 * client's PHPDoc — `@return list<Payment>` becoming `@return array<string,
 * Payment>` while the native type stays `array` — is invisible here, and
 * `resolveFacadeDocblockType()` treats any non-builtin, non-imported token as a
 * class name, so `@method static list<Payment> all()` resolves to
 * `...\Facades\list<Payment>` and this guard fails. Both are the same limit:
 * what is compared is the type system PHP enforces, not the one PHPStan reads.
 * Nothing drifts today — the tags on `src/Facades/Vpos.php` are all concrete
 * class or scalar types.
 *
 * The failure on a generic tag is loud, which is the safe direction, but the
 * shortest way out of it is the wrong one. **When a generic tag is genuinely
 * needed, teach the resolver — never weaken the tag.** Weakening
 * `list<Payment>` to `array` to get past this guard destroys exactly the
 * analysis the tags exist to restore: a merchant's `foreach` over the result
 * goes back to `mixed`, silently, in a package whose subject is money. Teaching
 * the resolver means resolving only the head of a `Head<...>` token as a class
 * name and recursing into its members; that is real work, and it is the work
 * this boundary is asking for rather than an excuse to skip.
 *
 * ## Why this is reflection and not an arch() expectation
 *
 * It relates a class to a *docblock*. pest-plugin-arch expresses relationships
 * between symbols and cannot read a comment, so there is no native expectation
 * being duplicated here and the standing prohibition on hand-rolling one does
 * not apply.
 */

/**
 * The `use` imports of the file a class is declared in, keyed by lowercased alias.
 *
 * PHP resolves a short name in a docblock exactly as it resolves one in code:
 * through the imports of the file it is written in, then relative to the
 * current namespace. This reproduces the first half of that so the second half
 * can be applied in resolveFacadeDocblockType().
 *
 * Only top-level class imports count. `use function` and `use const` import
 * different symbol tables, a closure's `use (...)` is not an import at all, and
 * a trait `use` inside a class body is not either — all three are skipped by
 * brace depth and by what follows the keyword, rather than by hoping none
 * appear.
 *
 * @param  ReflectionClass<object>  $class
 * @return array<string, string>
 */
function facadeFileImports(ReflectionClass $class): array
{
    $file = $class->getFileName();

    if ($file === false) {
        throw new RuntimeException(sprintf('%s is declared in no file this guard can read.', $class->getName()));
    }

    $source = file_get_contents($file);

    if ($source === false) {
        throw new RuntimeException(sprintf('Unable to read %s.', $file));
    }

    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));

    $imports = [];
    $depth = 0;
    $total = count($tokens);

    for ($index = 0; $index < $total; $index++) {
        $token = $tokens[$index];

        if ($token->text === '{') {
            $depth++;

            continue;
        }

        if ($token->text === '}') {
            $depth--;

            continue;
        }

        if ($depth !== 0 || ! $token->is(T_USE)) {
            continue;
        }

        $next = $tokens[$index + 1] ?? null;

        if ($next === null || $next->is([T_FUNCTION, T_CONST]) || $next->text === '(') {
            continue;
        }

        /*
         * The stream has had its whitespace filtered out, so `X as Y` would
         * concatenate into `XasY` and the alias would be lost silently — the
         * import would then resolve through the namespace fallback below and
         * name a class that does not exist, which is a guard reporting the
         * wrong thing rather than a guard failing. T_AS is re-spaced from the
         * token itself so the keyword is recognised as a keyword and not as
         * two letters that happen to sit between two names.
         */
        $statement = '';

        for ($index++; $index < $total && $tokens[$index]->text !== ';'; $index++) {
            $statement .= $tokens[$index]->is(T_AS) ? ' as ' : $tokens[$index]->text;
        }

        foreach (facadeImportEntries($statement) as $alias => $target) {
            $imports[$alias] = $target;
        }
    }

    return $imports;
}

/**
 * One `use` statement's body, expanded into alias => fully-qualified name.
 *
 * @return array<string, string>
 */
function facadeImportEntries(string $statement): array
{
    $prefix = '';
    $body = $statement;

    if (preg_match('/^(?<prefix>[\w\\\\]+)\\\\\{(?<body>.*)\}$/s', $statement, $group) === 1) {
        $prefix = $group['prefix'].'\\';
        $body = $group['body'];
    }

    $entries = [];

    foreach (explode(',', $body) as $item) {
        $item = trim($item);

        if ($item === '') {
            continue;
        }

        $parts = preg_split('/\s+as\s+/i', $item);

        if ($parts === false) {
            throw new RuntimeException(sprintf('Unable to read the import "%s".', $item));
        }

        $target = $prefix.ltrim(trim($parts[0]), '\\');
        $segments = explode('\\', $target);
        $alias = count($parts) > 1 ? trim($parts[1]) : end($segments);

        $entries[strtolower($alias)] = $target;
    }

    return $entries;
}

/**
 * A docblock type, rewritten into the fully-qualified form reflection reports.
 *
 * Union and intersection members are resolved individually, nullability is
 * preserved, and the builtin types are passed through untouched — there is no
 * namespace for `string` to be resolved into, and treating one as a class name
 * would silently invent `App\string`.
 *
 * @param  array<string, string>  $imports
 */
function resolveFacadeDocblockType(string $type, array $imports, string $namespace): string
{
    $builtins = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed',
        'never', 'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void',
    ];

    $parts = preg_split('/([|&])/', $type, -1, PREG_SPLIT_DELIM_CAPTURE);

    if ($parts === false) {
        throw new RuntimeException(sprintf('Unable to read the type "%s".', $type));
    }

    $resolved = '';

    foreach ($parts as $part) {
        if ($part === '|' || $part === '&') {
            $resolved .= $part;

            continue;
        }

        $name = trim($part);
        $nullable = str_starts_with($name, '?');
        $name = ltrim($name, '?');

        if ($name === '') {
            continue;
        }

        if (in_array(strtolower($name), $builtins, true)) {
            $resolved .= ($nullable ? '?' : '').strtolower($name);

            continue;
        }

        if (str_starts_with($name, '\\')) {
            $resolved .= ($nullable ? '?' : '').ltrim($name, '\\');

            continue;
        }

        $segments = explode('\\', $name);
        $head = strtolower($segments[0]);

        if (isset($imports[$head])) {
            $segments[0] = $imports[$head];
            $resolved .= ($nullable ? '?' : '').implode('\\', $segments);

            continue;
        }

        $resolved .= ($nullable ? '?' : '').($namespace === '' ? $name : $namespace.'\\'.$name);
    }

    return $resolved;
}

/**
 * Split a parameter list on the commas that separate parameters.
 *
 * Commas inside a bracketed type — an array shape, a generic, a default value
 * that is itself a list — do not separate parameters, so the split is depth
 * aware rather than a plain explode.
 *
 * @return list<string>
 */
function splitFacadeParameters(string $parameters): array
{
    $depth = 0;
    $current = '';
    $split = [];
    $openers = ['(' => true, '[' => true, '{' => true, '<' => true];
    $closers = [')' => true, ']' => true, '}' => true, '>' => true];

    foreach (str_split($parameters === '' ? ' ' : $parameters) as $character) {
        if (isset($openers[$character])) {
            $depth++;
        }

        if (isset($closers[$character])) {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $split[] = $current;
            $current = '';

            continue;
        }

        $current .= $character;
    }

    $split[] = $current;

    return array_values(array_filter(
        array_map(trim(...), $split),
        static fn (string $parameter): bool => $parameter !== '',
    ));
}

/**
 * One parameter, canonicalised so a docblock and reflection can be compared.
 *
 * @param  array<string, string>  $imports
 */
function canonicalFacadeParameter(string $parameter, array $imports, string $namespace): string
{
    $matched = preg_match(
        '/^(?<type>[^$&.]*?)\s*(?<byref>&)?\s*(?<variadic>\.\.\.)?\s*\$(?<name>\w+)\s*(?<optional>=.*)?$/s',
        $parameter,
        $matches,
    );

    if ($matched !== 1) {
        throw new RuntimeException(sprintf('Unable to read the parameter "%s".', $parameter));
    }

    $type = trim($matches['type']);

    return sprintf(
        '%s%s%s$%s%s',
        $type === '' ? '' : resolveFacadeDocblockType($type, $imports, $namespace).' ',
        $matches['byref'] === '' ? '' : '&',
        $matches['variadic'] === '' ? '' : '...',
        $matches['name'],
        ($matches['optional'] ?? '') === '' ? '' : ' = <default>',
    );
}

/**
 * A reflected type, spelled the way a docblock spells it.
 *
 * ReflectionType::__toString() would do this in one call and is deprecated, so
 * the three concrete type shapes are handled explicitly. `?` is emitted only
 * where PHP itself writes one — `mixed` and `null` allow null without being
 * nullable types — so the two sides agree on the spelling rather than on a
 * convention invented here. An unrecognised shape stops the run instead of
 * being flattened into a string that would compare wrongly.
 */
function reflectedTypeName(?ReflectionType $type): string
{
    if (! $type instanceof ReflectionType) {
        return '';
    }

    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'mixed' && $name !== 'null';

        return ($nullable ? '?' : '').$name;
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(reflectedTypeName(...), $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(reflectedTypeName(...), $type->getTypes()));
    }

    throw new RuntimeException(sprintf('This guard cannot read a %s.', $type::class));
}

/**
 * The same canonical form, built from reflection instead of from a docblock.
 */
function canonicalReflectedParameter(ReflectionParameter $parameter): string
{
    $type = reflectedTypeName($parameter->getType());

    return sprintf(
        '%s%s%s$%s%s',
        $type === '' ? '' : $type.' ',
        $parameter->isPassedByReference() ? '&' : '',
        $parameter->isVariadic() ? '...' : '',
        $parameter->getName(),
        $parameter->isOptional() && ! $parameter->isVariadic() ? ' = <default>' : '',
    );
}

it('keeps every facade tag in step with the class the facade resolves', function (): void {
    $facades = ProductionClasses::extending(Facade::class);

    expect($facades)->not->toBeEmpty(
        'No production facade was found at all, so this guard could not have seen a drifted tag even if one existed. '
        .'A facade is the one call site in this package with no signature of its own; if src/ ships one, it must be reachable from ProductionClasses.'
    );

    foreach ($facades as $facadeName) {
        $facade = new ReflectionClass($facadeName);

        /*
         * The accessor is read off the facade rather than assumed, because the
         * facade is the thing that declares what it resolves. It is protected,
         * which is why it is reached by reflection; PHP 8.1 made every method
         * invokable through reflection without setAccessible().
         */
        $accessor = $facade->getMethod('getFacadeAccessor');

        expect($accessor->getDeclaringClass()->getName())->toBe($facadeName, sprintf(
            '%s does not declare getFacadeAccessor() itself, so it resolves whatever its parent does and this guard cannot tell what its tags are supposed to describe.',
            $facadeName,
        ));

        $resolves = $accessor->invoke(null);

        expect($resolves)->toBeString(sprintf('%s::getFacadeAccessor() returns something this guard cannot read.', $facadeName));

        if (! is_string($resolves) || ! class_exists($resolves)) {
            throw new RuntimeException(sprintf(
                '%s::getFacadeAccessor() returns a container key that is not a class name, so there is no signature for its @method static tags to be checked against. '
                .'A facade in this package resolves its target class by name, which is what makes the facade and a constructor-injected instance the same object.',
                $facadeName,
            ));
        }

        $target = new ReflectionClass($resolves);
        $namespace = $facade->getNamespaceName();
        $imports = facadeFileImports($facade);

        // --- What the class actually offers. ---

        $reflected = [];

        foreach ($target->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            $reflected[$method->getName()] = [
                'return' => reflectedTypeName($method->getReturnType()),
                'parameters' => array_map(canonicalReflectedParameter(...), $method->getParameters()),
            ];
        }

        // --- What the docblock claims it offers. ---

        $docblock = $facade->getDocComment();

        if ($docblock === false) {
            throw new RuntimeException(sprintf('%s carries no docblock, so it declares no signature at all.', $facadeName));
        }

        /*
         * Every @method line is counted before any is parsed, so a tag written
         * in a shape this guard does not understand is reported as unreadable
         * rather than silently dropped — a dropped tag would read here as a
         * missing tag, which is a different defect with a different fix.
         */
        $lines = preg_match_all('/^\s*\*\s*@method\b.*$/m', $docblock, $allTags);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to scan %s\'s docblock for @method tags.', $facadeName));
        }

        $parsed = preg_match_all(
            '/^\s*\*\s*@method\s+static\s+(?<return>\S+)\s+(?<name>\w+)\s*\((?<parameters>.*)\)\s*$/m',
            $docblock,
            $tags,
            PREG_SET_ORDER,
        );

        if ($parsed === false) {
            throw new RuntimeException(sprintf('Unable to scan %s\'s docblock for @method static tags.', $facadeName));
        }

        expect($parsed)->toBe($lines, sprintf(
            "%s carries %d @method tag(s) but only %d of them are readable `@method static <return> name(...)` declarations. Every tag on a facade is a static call, and an unreadable one is a signature PHPStan may be reading differently from this guard. The tags are:\n%s",
            $facadeName,
            $lines,
            $parsed,
            implode("\n", array_map(trim(...), $allTags[0])),
        ));

        $declared = [];

        foreach ($tags as $tag) {
            $declared[$tag['name']] = [
                'return' => resolveFacadeDocblockType($tag['return'], $imports, $namespace),
                'parameters' => array_map(
                    static fn (string $parameter): string => canonicalFacadeParameter($parameter, $imports, $namespace),
                    splitFacadeParameters($tag['parameters']),
                ),
            ];
        }

        // --- Both directions of the set. ---

        $missing = array_keys(array_diff_key($reflected, $declared));
        $stale = array_keys(array_diff_key($declared, $reflected));

        expect($missing)->toBe([], sprintf(
            '%s declares no @method static tag for %s::%s(). Without a tag the call resolves to mixed and every type error after it goes unreported, which on a facade is the whole of the analysis.',
            $facadeName,
            $target->getName(),
            implode('(), '.$target->getName().'::', $missing),
        ));

        expect($stale)->toBe([], sprintf(
            '%s declares an @method static tag for %s, which %s does not have. A tag for a method that does not exist is worse than a missing one: PHPStan trusts it, so a merchant\'s call to it is analysed as correct and fails at runtime.',
            $facadeName,
            implode(', ', $stale),
            $target->getName(),
        ));

        // --- And the signatures behind them. ---

        foreach ($reflected as $name => $signature) {
            expect($declared[$name]['return'])->toBe($signature['return'], sprintf(
                '%s tags %s() as returning `%s`, but %s::%s() returns `%s`. A drifted return type is the dangerous direction: PHPStan analyses every merchant call site against what the tag says, reports nothing, and this package stays green through exactly that change.',
                $facadeName,
                $name,
                $declared[$name]['return'],
                $target->getName(),
                $name,
                $signature['return'],
            ));

            expect($declared[$name]['parameters'])->toBe($signature['parameters'], sprintf(
                '%s tags %s() as taking (%s), but %s::%s() takes (%s). The tag is the only signature a static analyser sees for a facade call, so a parameter that has moved, changed type or stopped being optional is accepted at every call site and fails at runtime.',
                $facadeName,
                $name,
                implode(', ', $declared[$name]['parameters']),
                $target->getName(),
                $name,
                implode(', ', $signature['parameters']),
            ));
        }
    }
});
