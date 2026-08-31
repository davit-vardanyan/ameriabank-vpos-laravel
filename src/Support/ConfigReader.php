<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Support;

use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

use function get_debug_type;
use function is_string;

/**
 * Reads this package's configuration, in the one place that reads it.
 *
 * ## Why one reader rather than two
 *
 * The service provider and `vpos:check` each carried an identical three-branch
 * reader, and the command's own docblock stated the coupling as a requirement:
 * it *must* read a key exactly as the provider reads it, because the provider
 * is what builds the client, so a key the command accepted and the provider
 * refused — or the reverse — would print one account of the configuration and
 * act on another. A requirement stated in prose and duplicated in code holds
 * only for as long as two docblocks agree. Here it holds because there is one
 * reader and neither caller keeps a copy.
 *
 * The drift that made this worth ending is not cosmetic, and its direction
 * changed once both copies started refusing a wrong-typed value. While they
 * only differed in how they coerced, a divergence cost a *message*. Now it
 * costs *control flow*: a command-side copy that regressed to reading a
 * wrong-typed value as blank would print `Environment: ` and a base URL
 * derived from that blank reading, and then be refused by the provider a few
 * lines later — two accounts of one key in one run.
 *
 * ## Three outcomes, and the middle one is why this is not a one-line ternary
 *
 * - a string is returned as it was configured;
 * - `null` — a missing key, or an environment variable that is not set — reads
 *   as blank, and blank is refused by whichever component was asked for it, in
 *   that component's own words. The value really is absent, so "must not be
 *   blank" is the true account of it;
 * - anything else is **set to the wrong type**, and is refused here by name.
 *   Reading it as blank too would send the absent-value refusal for a value
 *   that is present, and an operator told a key is missing goes looking for a
 *   missing one.
 *
 * ## The repository is held, never passed
 *
 * `string()` takes a key and nothing else, and the repository it reads sits on
 * `$this`. That shape is deliberate, and what it buys is measured rather than
 * assumed:
 *
 * - the configured value is reduced to a type name by `get_debug_type()` at
 *   the throw site, so it is never an argument to a call that outlives this
 *   method. The exception is constructed inside the factory, which makes the
 *   factory's own call frame 0 of the trace the exception carries, and
 *   `getTraceAsString()` renders a frame's arguments verbatim into whatever
 *   logs it;
 * - this method's own call is frame 1, and its only argument is the key. The
 *   shape this replaced took `(Repository $config, string $key)`, which put
 *   `Object(Illuminate\Config\Repository)` — holding the ClientID, the
 *   username and the password together — into frame 1, where an error reporter
 *   that walks `getTrace()` itself and serialises argument objects can read
 *   it. Sentry and Flare both do, and that is a real integration for a
 *   payments merchant;
 * - holding the repository on `$this` does not reopen the same gap by another
 *   route. `Exception::getTrace()` produces no `object` key at all — measured
 *   on PHP 8.3.28 and on 8.5.7, under both `zend.exception_ignore_args`
 *   settings — so the instance holding the repository is not in the trace.
 *
 * What that establishes is narrow, and it is worth stating exactly: **frames 0
 * and 1 of a refusal raised here carry a key and a type name and nothing
 * else.** It establishes nothing about frames further out, and there are such
 * frames — the container hands itself to the closure the service provider
 * registers, and that is Laravel's frame, not one this package declares.
 * `README.md` states the residue rather than leaving a reader to assume it
 * away.
 *
 * ## Built per read, never cached
 *
 * A caller constructs one of these from whichever configuration repository is
 * in force at that moment and keeps no instance. A cached reader would pin the
 * repository it was built with, so an application that replaces its
 * configuration between reads — a long-lived worker reloading it, a test
 * rebinding `config` as an instance — would go on reading a configuration that
 * is no longer in force. That is the hazard `BackUrlResolver` is bound rather
 * than shared to avoid, one level up and with credentials rather than a URL at
 * stake, and it takes the same answer: rebuilding one small object per read is
 * cheaper than a silent divergence between the configured value and the used
 * one.
 *
 * @internal wiring, not surface. This exists so that the service provider and
 *           `vpos:check` cannot disagree about a key; nothing outside this
 *           package should read configuration through it, and its shape is
 *           free to change whenever those two callers need it to. Contrast
 *           `BackUrlResolver`, which is deliberately public because it is the
 *           only supported way to build a value a merchant has to pass.
 */
final readonly class ConfigReader
{
    /**
     * The configuration namespace, and the published file's base name.
     *
     * It lives here rather than on the service provider because this is the
     * class whose whole job is that namespace, and because two consumers
     * composing keys under a literal each is the duplication this class was
     * extracted to end — closing one copy of a reader while opening a second
     * copy of the prefix it reads under would trade the defect for itself.
     * The provider cites it for the file it merges and publishes.
     */
    public const string CONFIG_KEY = 'ameriabank-vpos';

    public function __construct(private Repository $config) {}

    /**
     * A package configuration value, untouched.
     *
     * One place composes the key, so the namespace prefix is written once and
     * every caller sees exactly what the repository holds — including whether
     * it holds `null`, which is the distinction `vpos:check` turns into a line
     * and `string()` below turns into a refusal.
     */
    public function value(string $key): mixed
    {
        return $this->config->get(self::CONFIG_KEY.'.'.$key);
    }

    /**
     * A package configuration value as a string, or a refusal naming its type.
     *
     * The class docblock above gives the reasoning for all three outcomes and
     * for why the value is reduced to a type name before it crosses into the
     * factory.
     *
     * @throws ConfigurationException when the key is set to something other than a string
     */
    public function string(string $key): string
    {
        $value = $this->value($key);

        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        throw ConfigurationException::notAString($key, get_debug_type($value));
    }
}
