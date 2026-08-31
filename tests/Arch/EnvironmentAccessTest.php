<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ProductionClasses;
use Illuminate\Support\Env;

/*
 * env() returns null once an application has cached its configuration, so a
 * package that reads the environment anywhere but in its config file works in
 * development and silently loses every credential in production. The failure is
 * a blank ClientID reaching the gateway, which answers response code 20 — a
 * message about credentials for a mistake that has nothing to do with them.
 *
 * config/ameriabank-vpos.php is the one file allowed to call it, and it is out
 * of reach of this expectation by construction: it belongs to no namespace, so
 * no PSR-4 prefix in composer.json maps to it and nothing here can see it.
 *
 * ---
 *
 * The rule is about reading the environment, not about one function name.
 * Guarding a single spelling of it was the defect this file was widened to fix:
 * a guard naming only `env` stays green through the exact change it exists to
 * catch, because every other spelling has the same production symptom.
 *
 * Three spellings are symbols and one expectation covers them:
 *
 *   - env() is the framework helper, and the one merchants and contributors
 *     reach for first;
 *   - getenv() is PHP's own, unaffected by config caching in the sense that it
 *     still returns a value — which is worse, not better: it reads the real
 *     process environment, so a cached-config application gets a value here and
 *     null from env() a line later, and the two disagree without either failing;
 *   - Illuminate\Support\Env is what env() delegates to. Env::get() is the same
 *     read with the helper spelled out, and it is the spelling somebody arrives
 *     at when a static analyser or an IDE offers to resolve the helper. Any
 *     guard that catches env() and not this one catches a typing habit rather
 *     than a rule.
 *
 * Larastan's larastan.noEnvCallsOutsideOfConfig rule is a second, independent
 * holder — but only of the first spelling. It does not see getenv(), Env::,
 * $_ENV or $_SERVER, which is why this guard still has to.
 */
arch('nothing reads the environment outside the configuration file', function (): void {
    expect(['env', 'getenv', Env::class])->not->toBeUsed();
});

/*
 * $_ENV and $_SERVER are the two spellings pest-plugin-arch cannot express.
 *
 * They are superglobals, not symbols: there is no function to name in
 * toBeUsed() and no class to name in toUse(), so the arch expectation above
 * has no way to reach them however it is written. They are nevertheless the
 * same read — Illuminate\Support\Env's own adapter consults $_ENV and $_SERVER
 * before it consults getenv(), so a package bypassing the helper and indexing
 * the superglobal directly reaches precisely the same value by precisely the
 * same route, and loses it in production for precisely the same reason.
 *
 * The sweep is therefore textual, and it is textual only because no structural
 * expression of it exists. It is tokenised rather than grepped: a variable
 * token is a variable token, so the guard cannot be fooled by the name
 * appearing inside a string, a comment or a docblock, and cannot fire on the
 * prose above it.
 *
 * The subject list is ProductionClasses::files() — the files the production
 * PSR-4 map actually resolves to — so it is the same source of truth every
 * other guard here derives from, and a new production file joins it without
 * anybody editing this test.
 */
it('reads no environment superglobal in production code', function (): void {
    $forbidden = ['$_ENV', '$_SERVER'];

    $files = ProductionClasses::files();

    expect($files)->not->toBeEmpty(
        'The production PSR-4 map resolved to no files at all, so this sweep could not have seen a superglobal read even if one existed.'
    );

    $found = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read %s.', $file));
        }

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is(T_VARIABLE) && in_array($token->text, $forbidden, true)) {
                $found[] = sprintf('%s:%d reads %s', $file, $token->line, $token->text);
            }
        }
    }

    expect($found)->toBe([], sprintf(
        "Production code reads the environment through a superglobal:\n%s\n"
        .'Only config/ameriabank-vpos.php may read the environment. Everywhere else takes its values from the container-bound configuration, '
        .'because a value read here is read before an installing application has had the chance to cache, override or publish it.',
        implode("\n", $found),
    ));
});
