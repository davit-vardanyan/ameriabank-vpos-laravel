<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Http\HttpTransport;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\TestCase;
use DavitVardanyan\AmeriabankVpos\Vpos;

/*
 * The bridge accepts exactly the attempt budget the client accepts.
 *
 * `AmeriabankVposServiceProvider::maxAttempts()` refuses a `max_attempts`
 * outside 1..5 itself rather than letting the client refuse it, and that is a
 * deliberate decision this guard does not touch: the client is handed a number
 * with no idea where it came from, so its message names no configuration key
 * and no environment variable, while the bridge can name both. The bridge keeps
 * owning its own validation.
 *
 * What was unheld was the *duplication that decision creates*. The two bounds
 * are written in the bridge and also written in the client, and nothing
 * compared them — so the day the client narrows its range to 1..3, this package
 * goes on accepting 4 and 5, builds the client with one of them, and the
 * merchant gets the client's own refusal naming no key at all. That is the
 * exact failure the bridge-side check exists to prevent, arriving through the
 * gap the bridge-side check opened.
 *
 * ## The premise that made it unheld was false
 *
 * The provider's docblock recorded the duplication and justified it: the client
 * *"keeps it private (`HttpTransport::MINIMUM_ATTEMPTS` and
 * `MAXIMUM_ATTEMPTS`), so there is nothing to derive it from."* Private is not
 * unreadable. `ReflectionClassConstant::getValue()` reads a private constant
 * without ceremony, and measured on the installed client both answer:
 * MINIMUM_ATTEMPTS is 1 and MAXIMUM_ATTEMPTS is 5. So the claim can be held
 * rather than argued for, and it is held here.
 *
 * ## Both sides are derived, which is what makes this an assertion at all
 *
 * The client's side is the two constants, read by reflection at test time. The
 * bridge's side is **not read from its source** — it is the set of values the
 * provider actually accepts, established by configuring each candidate and
 * asking the container for a client. A guard that read `1` and `5` out of the
 * provider and compared them to `1` and `5` from the client would be comparing
 * two literals and asserting nothing about behaviour; this one would catch a
 * bound that is written correctly and compared with the wrong operator.
 *
 * The probe spans a margin either side of the client's range, so a bridge that
 * accepted *more* than the client does is caught as well as one that accepts
 * fewer. The margin is a probe width and not a bound: it says how far past the
 * edge to look, and moving it changes nothing about what is asserted.
 *
 * The second expectation covers the other use of the same two locals. The
 * provider hands them to `ConfigurationException::invalidMaxAttempts()` as well
 * as comparing against them, and a merchant reads the sentence rather than the
 * comparison — so a refusal that enforces 1..5 while printing 1..4 is a real
 * failure that the first expectation cannot see. It is asserted by rebuilding
 * the expected message from the *client's* constants through the package's own
 * factory, so a reworded message is still compared on the only thing this guard
 * is about: which two numbers the provider put in it.
 *
 * ## What this deliberately does not assert
 *
 * That the client *enforces* its own constants. Reading them establishes what
 * they are, not what is done with them, and this package cannot hold a claim
 * about the core's method bodies. `tests/Feature/ConfigurationTest.php` covers
 * the consequence from the other end: a value this package refuses never
 * reaches the client's own refusal at all.
 *
 * The client class is named here rather than derived, and that name is held
 * elsewhere: `tests/Support/ClientInternals.php` imports the same class to read
 * the transport's private properties, so a core that renamed or moved it stops
 * this suite loudly rather than leaving this guard reading a stale constant.
 * The constants themselves are checked for existence before they are read, so a
 * core that keeps the class and drops the constants reports that rather than
 * failing on a reflection error nobody can interpret.
 *
 * This file boots an application because the bridge's side of the comparison is
 * a behaviour, and a behaviour needs something to behave in. `tests/Pest.php`
 * binds the base case to the Feature suite only, so this file binds it for
 * itself.
 */

uses(TestCase::class);

/**
 * One of the client's own attempt bounds, read from the class that owns it.
 */
function clientAttemptBound(string $constant): int
{
    $reflection = new ReflectionClass(HttpTransport::class);

    if (! $reflection->hasConstant($constant)) {
        throw new RuntimeException(sprintf(
            '%s::%s no longer exists, so this package cannot check its own attempt bounds against the client\'s. '
            .'Find where the client states the bound now and read it from there — the alternative is a bridge '
            .'that enforces a range nothing checks.',
            HttpTransport::class,
            $constant,
        ));
    }

    $value = (new ReflectionClassConstant(HttpTransport::class, $constant))->getValue();

    if (! is_int($value)) {
        throw new RuntimeException(sprintf(
            '%s::%s is not an integer, so it is not the bound this guard was written to read.',
            HttpTransport::class,
            $constant,
        ));
    }

    return $value;
}

/**
 * Configures an attempt budget, and forgets the client built under the last one.
 *
 * The singleton is forgotten because the whole point of the probe is that the
 * provider's closure runs again against the configuration just set; without
 * that, every candidate after the first would be answered by the client the
 * first one built.
 */
function configureAttemptBudget(int $budget): void
{
    vposConfig(['ameriabank-vpos.max_attempts' => $budget]);

    app()->forgetInstance(Vpos::class);
}

/**
 * Whether the provider builds a client from this attempt budget.
 */
function acceptsAttemptBudget(int $budget): bool
{
    configureAttemptBudget($budget);

    try {
        app()->make(Vpos::class);
    } catch (ConfigurationException) {
        return false;
    }

    return true;
}

/**
 * The refusal this attempt budget produces, or a stop if it produces none.
 */
function refusalForAttemptBudget(int $budget): ConfigurationException
{
    configureAttemptBudget($budget);

    try {
        app()->make(Vpos::class);
    } catch (ConfigurationException $failure) {
        return $failure;
    }

    throw new RuntimeException(sprintf(
        'The provider accepted an attempt budget of %d, which the expectation above reports as the range '
        .'disagreement it is. There is no refusal here to read the printed bounds out of.',
        $budget,
    ));
}

it('accepts exactly the attempt budgets the client accepts', function (): void {
    $lowest = clientAttemptBound('MINIMUM_ATTEMPTS');
    $highest = clientAttemptBound('MAXIMUM_ATTEMPTS');
    $margin = 2;

    $accepted = [];

    for ($candidate = $lowest - $margin; $candidate <= $highest + $margin; $candidate++) {
        if (acceptsAttemptBudget($candidate)) {
            $accepted[] = $candidate;
        }
    }

    expect($accepted)->toBe(range($lowest, $highest), sprintf(
        'This package accepts the attempt budgets [%s], and the client accepts %d..%d — read from %s::MINIMUM_ATTEMPTS '
        .'and ::MAXIMUM_ATTEMPTS at test time. The provider refuses an out-of-range budget itself so that the '
        .'refusal can name ameriabank-vpos.max_attempts and AMERIABANK_VPOS_MAX_ATTEMPTS, which the client\'s own '
        .'refusal cannot. That is only worth doing while the two ranges agree: accept one value too many and the '
        .'client raises its own ValidationException naming no key, accept one too few and a budget the client '
        .'would have honoured is refused by this package alone.',
        $accepted === [] ? '(nothing)' : implode(', ', array_map(strval(...), $accepted)),
        $lowest,
        $highest,
        HttpTransport::class,
    ));
});

it('names the client\'s own bounds in the refusal a merchant reads', function (): void {
    $lowest = clientAttemptBound('MINIMUM_ATTEMPTS');
    $highest = clientAttemptBound('MAXIMUM_ATTEMPTS');

    foreach ([$lowest - 1, $highest + 1] as $outside) {
        expect(refusalForAttemptBudget($outside)->getMessage())->toBe(
            ConfigurationException::invalidMaxAttempts(get_debug_type($outside), $lowest, $highest)->getMessage(),
            sprintf(
                'The refusal for an attempt budget of %d does not print the client\'s own bounds of %d..%d. The '
                .'provider hands two numbers to ConfigurationException::invalidMaxAttempts() and compares against '
                .'the same two, so a merchant reads whichever pair the provider holds — and a message printing a '
                .'range the client does not enforce sends them to change a value that was never the problem. The '
                .'expected message here is built by this package\'s own factory from the client\'s constants, so '
                .'rewording the sentence cannot make this fail; only changing the numbers can.',
                $outside,
                $lowest,
                $highest,
            ),
        );
    }
});
