<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support;

use PhpToken;
use RuntimeException;

use function array_filter;
use function array_pop;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function get_parent_class;
use function implode;
use function ltrim;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * The mechanics several textual guards need, written once.
 *
 * Three guards in `tests/Arch/` ask questions of production source that no
 * `arch()` expectation can express — what a doc comment says, and what a `new`
 * expression constructs. Each needs the same two things underneath: a token
 * stream rather than a text search, and, for the second, PHP's own name
 * resolution rules.
 *
 * **Tokens rather than a text search, for the reason every sweep here gives.**
 * A grep cannot tell an annotation in a doc comment from the same word in a
 * string literal, and cannot tell a construction in code from the same phrase
 * inside the comment explaining why it is forbidden — including inside this
 * comment, which a grep-based guard would report as a violation of itself.
 *
 * **Name resolution is done properly, and that is the whole point of the
 * class.** A guard that matched only unqualified names would be a new bypass
 * the day somebody wrote a leading backslash or imported the class under an
 * alias, so `use` statements — plain, aliased, grouped — the current namespace,
 * and `self`, `static` and `parent` are all resolved. What is deliberately not
 * resolved is a name that is not written down: a variable class name, an
 * expression, or a constant holding one yields a null name rather than a
 * guess, because a guess would be reported as a fact.
 *
 * It walks nothing of its own. Every caller passes a file that came from
 * `ProductionClasses::files()`, so the subject list of every guard built on
 * this is the same one, derived from composer.json's PSR-4 map.
 */
final class ProductionSource
{
    /**
     * Every doc comment in a file, with the line it starts on.
     *
     * Only doc comments. An ordinary line or block comment carries no
     * annotation meaning to any tool, so a marking written in one is not a
     * marking, and reporting it would be reporting a word rather than a fact.
     *
     * @return list<array{line: int, text: string}>
     */
    public static function docComments(string $file): array
    {
        $comments = [];

        foreach (PhpToken::tokenize(self::read($file)) as $token) {
            if ($token->is(T_DOC_COMMENT)) {
                $comments[] = ['line' => $token->line, 'text' => $token->text];
            }
        }

        return $comments;
    }

    /**
     * Every `new` expression in a file, resolved.
     *
     * `name` is the fully-qualified class the expression constructs, or null
     * when the file does not say. `enclosing` is the class whose body the
     * expression sits in, or null at file scope. `thrown` records whether the
     * expression is the operand of a `throw`, which is the narrower construct
     * the exception guard held before it was widened.
     *
     * An anonymous class declares no name, so a construction inside one is
     * attributed to the class the anonymous one sits in. That errs towards
     * reporting rather than towards silence, which is the direction a guard
     * should err in.
     *
     * @return list<array{line: int, name: ?string, enclosing: ?string, thrown: bool}>
     */
    public static function constructions(string $file): array
    {
        $tokens = self::significantTokens($file);
        $total = count($tokens);

        $namespace = '';
        $aliases = [];
        $classes = [];
        $depth = 0;
        $pending = null;
        $found = [];

        for ($index = 0; $index < $total; $index++) {
            $token = $tokens[$index];

            if ($token->is(T_NAMESPACE) && ! self::isFollowedBySeparator($tokens, $index)) {
                $consumed = 0;
                $namespace = self::readName($tokens, $index + 1, $consumed);
                $index += $consumed;

                continue;
            }

            if ($token->is(T_USE) && $classes === [] && $depth === 0) {
                $index = self::readImports($tokens, $index, $aliases);

                continue;
            }

            if (self::opensBlock($token)) {
                $depth++;

                if ($pending !== null) {
                    $classes[] = ['name' => $pending, 'depth' => $depth];
                    $pending = null;
                }

                continue;
            }

            if ($token->text === '}') {
                $last = count($classes) - 1;

                if ($last >= 0 && $classes[$last]['depth'] === $depth) {
                    array_pop($classes);
                }

                $depth--;

                continue;
            }

            if (self::declaresClass($tokens, $index)) {
                $pending = self::declaredName($tokens, $index, $namespace);

                continue;
            }

            if (! $token->is(T_NEW)) {
                continue;
            }

            $enclosing = $classes === [] ? null : $classes[count($classes) - 1]['name'];

            $found[] = [
                'line' => $token->line,
                'name' => self::constructedName($tokens, $index + 1, $namespace, $aliases, $enclosing),
                'enclosing' => $enclosing,
                'thrown' => $index > 0 && $tokens[$index - 1]->is(T_THROW),
            ];
        }

        return $found;
    }

    /**
     * A file's contents, or a refusal naming it.
     *
     * A guard that cannot read its subject must not report on it, so an
     * unreadable file stops the run rather than being swept as empty.
     */
    public static function read(string $file): string
    {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        return $source;
    }

    /**
     * The file's tokens with whitespace and ordinary comments removed.
     *
     * @return list<PhpToken>
     */
    private static function significantTokens(string $file): array
    {
        return array_values(array_filter(
            PhpToken::tokenize(self::read($file)),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }

    /**
     * Whether this token opens a brace-delimited block.
     *
     * An interpolated expression inside a double-quoted string opens with its
     * own token type and closes with an ordinary brace, so counting only the
     * ordinary form would leave the depth permanently wrong from the first
     * interpolation onwards — and a wrong depth pops the enclosing class early,
     * which reads as "this construction is at file scope" and is exactly the
     * kind of quiet miss these guards exist to avoid.
     */
    private static function opensBlock(PhpToken $token): bool
    {
        return $token->text === '{'
            || $token->is(T_CURLY_OPEN)
            || $token->is(T_DOLLAR_OPEN_CURLY_BRACES);
    }

    /**
     * Whether the token at $index is a class-like declaration keyword.
     *
     * Two things wear the same keyword and neither declares a named class: the
     * `::class` constant tokenises its second half as the class keyword, and an
     * anonymous class declares a body belonging to no name at all. Both are
     * excluded here rather than guessed at later.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function declaresClass(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if (! $token->is(T_CLASS) && ! $token->is(T_INTERFACE) && ! $token->is(T_TRAIT) && ! $token->is(T_ENUM)) {
            return false;
        }

        $previous = $tokens[$index - 1] ?? null;

        if ($previous === null) {
            return true;
        }

        return ! $previous->is(T_DOUBLE_COLON) && ! $previous->is(T_NEW);
    }

    /**
     * The fully-qualified name a class-like declaration introduces.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function declaredName(array $tokens, int $index, string $namespace): ?string
    {
        $total = count($tokens);

        for ($cursor = $index + 1; $cursor < $total; $cursor++) {
            if ($tokens[$cursor]->text === '{') {
                return null;
            }

            if ($tokens[$cursor]->is(T_STRING)) {
                return $namespace === '' ? $tokens[$cursor]->text : $namespace.'\\'.$tokens[$cursor]->text;
            }
        }

        return null;
    }

    /**
     * The class a `new` at $index constructs, or null when the source does not
     * say.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function constructedName(
        array $tokens,
        int $index,
        string $namespace,
        array $aliases,
        ?string $enclosing,
    ): ?string {
        if ($index >= count($tokens)) {
            return null;
        }

        $token = $tokens[$index];

        if ($token->is(T_STATIC)) {
            return $enclosing;
        }

        if (! $token->is(T_STRING) && ! $token->is(T_NAME_QUALIFIED)
            && ! $token->is(T_NAME_FULLY_QUALIFIED) && ! $token->is(T_NAME_RELATIVE)) {
            return null;
        }

        $consumed = 0;

        return self::resolve(self::readName($tokens, $index, $consumed), $namespace, $aliases, $enclosing);
    }

    /**
     * PHP's own name resolution, applied to a name as it was written.
     *
     * @param  array<string, string>  $aliases
     */
    private static function resolve(string $written, string $namespace, array $aliases, ?string $enclosing): ?string
    {
        if ($written === '') {
            return null;
        }

        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        $lowered = strtolower($written);

        if ($lowered === 'self' || $lowered === 'static') {
            return $enclosing;
        }

        if ($lowered === 'parent') {
            if ($enclosing === null) {
                return null;
            }

            $parent = get_parent_class($enclosing);

            return $parent === false ? null : $parent;
        }

        if (str_starts_with($lowered, 'namespace\\')) {
            $relative = substr($written, 10);

            return $namespace === '' ? $relative : $namespace.'\\'.$relative;
        }

        $segments = explode('\\', $written);
        $first = strtolower($segments[0]);

        if (isset($aliases[$first])) {
            $segments[0] = $aliases[$first];

            return implode('\\', $segments);
        }

        return $namespace === '' ? $written : $namespace.'\\'.$written;
    }

    /**
     * Reads a name written across one or more tokens, from $index onwards.
     *
     * PHP 8 emits a qualified name as a single token, but a name split by a
     * comment still arrives as several, so the pieces are joined rather than
     * assumed to be one.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function readName(array $tokens, int $index, int &$consumed): string
    {
        $total = count($tokens);
        $parts = [];
        $cursor = $index;

        while ($cursor < $total) {
            $token = $tokens[$cursor];

            if (! $token->is(T_STRING) && ! $token->is(T_NAME_QUALIFIED)
                && ! $token->is(T_NAME_FULLY_QUALIFIED) && ! $token->is(T_NAME_RELATIVE)
                && ! $token->is(T_NS_SEPARATOR)) {
                break;
            }

            $parts[] = $token->text;
            $cursor++;
        }

        $consumed = $cursor - $index;

        return implode('', $parts);
    }

    /**
     * Whether a `namespace` keyword introduces the relative-name operator
     * rather than a declaration.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function isFollowedBySeparator(array $tokens, int $index): bool
    {
        return isset($tokens[$index + 1]) && $tokens[$index + 1]->is(T_NS_SEPARATOR);
    }

    /**
     * Records every class alias a `use` statement introduces.
     *
     * Plain, aliased and grouped imports are all read; function and constant
     * imports are skipped because they alias something that is not a class.
     *
     * **A closure's capture list does reach here, and that is why nothing is
     * skipped until something has been imported.** The caller offers every
     * `use` it meets at file scope outside a class body, and a file-scope
     * closure — `$make = function () use ($config) { … };` — carries one. Its
     * next token is a parenthesis, so `readName()` consumes nothing, and the
     * earlier version of this method then returned `skipToSemicolon()` for it:
     * the caller resumed after the first `;` at or after the capture list,
     * which is *inside the closure body*, and every construction before that
     * semicolon was unseen. Silent, and silent in the direction two absence
     * assertions read as green.
     *
     * So an import statement returns its final token as before, and a `use`
     * that imported nothing returns the index it was entered at, leaving the
     * caller to walk the capture list and the body as ordinary tokens. A
     * capture list has no bearing on name resolution, so walking it costs
     * nothing; skipping it cost a guard.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $aliases
     * @return int the index of the statement's final token, or of the `use`
     *             itself when the statement imported nothing
     */
    private static function readImports(array $tokens, int $index, array &$aliases): int
    {
        $total = count($tokens);
        $cursor = $index + 1;

        if ($cursor >= $total || $tokens[$cursor]->is(T_FUNCTION) || $tokens[$cursor]->is(T_CONST)) {
            return self::skipToSemicolon($tokens, $cursor);
        }

        $prefix = '';
        $imported = false;

        while ($cursor < $total) {
            $consumed = 0;
            $name = self::readName($tokens, $cursor, $consumed);

            if ($consumed === 0) {
                break;
            }

            $imported = true;
            $cursor += $consumed;

            if ($cursor < $total && $tokens[$cursor]->text === '{') {
                $prefix = $name;
                $cursor++;

                continue;
            }

            $alias = self::lastSegment($name);

            if ($cursor + 1 < $total && $tokens[$cursor]->is(T_AS)) {
                $alias = $tokens[$cursor + 1]->text;
                $cursor += 2;
            }

            $aliases[strtolower($alias)] = ltrim($prefix.$name, '\\');

            if ($cursor < $total && $tokens[$cursor]->text === ',') {
                $cursor++;

                continue;
            }

            break;
        }

        return $imported ? self::skipToSemicolon($tokens, $cursor) : $index;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function skipToSemicolon(array $tokens, int $index): int
    {
        $total = count($tokens);

        for ($cursor = $index; $cursor < $total; $cursor++) {
            if ($tokens[$cursor]->text === ';') {
                return $cursor;
            }
        }

        return $total - 1;
    }

    private static function lastSegment(string $name): string
    {
        $segments = explode('\\', $name);

        return $segments[count($segments) - 1];
    }
}
