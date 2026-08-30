<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubClientException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubHttpClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Psr\Http\Client\ClientInterface;

/**
 * The standing caveat, written out here rather than read from the command.
 *
 * A caveat that changes whenever the code changes is not a caveat. This is the
 * sentence the package has committed to printing on every run, so the test
 * carries its own copy and goes red if the command stops saying it.
 */
const NO_RELIABLE_CHECK = 'The Ameriabank vPOS gateway offers no reliable credential check. No operation this '
    .'package can safely call has been observed answering in a way that proves credentials are valid when the '
    .'order asked about is not one the merchant registered. InitPayment is the only operation with an unambiguous '
    .'credential rejection, and it is barred from a diagnostic because it registers a real order.';

/**
 * The OrderID the blind probe asks about, restated as a contract.
 *
 * The command derives it from a named local; this file pins the value, because
 * the whole point of a sentinel is that it is a fixed, documented number rather
 * than whatever the code happens to hold today.
 */
const SENTINEL_ORDER_ID = -999999999;

/**
 * An OrderID a merchant plausibly registered, for the --order-id mode.
 */
const MERCHANT_ORDER_ID = 774312;

/**
 * The PaymentID the gateway hands back on a success.
 *
 * Deliberately not GUID-shaped and deliberately self-describing: it is what the
 * "never echo the PaymentId" assertions look for, and a value that could be
 * mistaken for anything else would make those assertions weaker than they read.
 */
const RETURNED_PAYMENT_ID = 'PAYMENTID-MUST-NEVER-BE-ECHOED-ca7ce9d8';

/**
 * The success answer, in the shape GetPaymentId actually returns.
 *
 * Three details are the endpoint's own and not an invention: the key is
 * `PaymentId` with a lowercase `d` on this model alone, `ResponseMessage`
 * precedes `ResponseCode`, and the message is the **empty string** — which is
 * what the one observed successful call carried. It is not a placeholder for a
 * message that was dropped.
 */
const SUCCESS_BODY = '{"PaymentId":"'.RETURNED_PAYMENT_ID.'","ResponseMessage":"","ResponseCode":"00"}';

/**
 * A failure answer that also carries a PaymentId, so the leak assertions have
 * something to find on a path that is not the success path.
 */
const OTHER_CODE_BODY = '{"PaymentId":"'.RETURNED_PAYMENT_ID.'","ResponseMessage":"System Error","ResponseCode":"550"}';

const REJECTION_ADVICE = '. Check client_id, username and password against the set the bank issued for this '
    .'environment — they differ between test and production.';

/**
 * The second line a production verdict carries, and only a production one.
 *
 * Written out once because two tests need it in opposite directions: the
 * sandbox verdict must not carry it and the production verdict must.
 */
const PRODUCTION_CAVEAT = 'No probe has ever reached a production host. Every observation this verdict rests on '
    .'was made against the sandbox, so a production result has no observational backing: treat it as an '
    .'indication, and let a real payment be the confirmation.';

/**
 * What the blind mode tells the merchant before it sends anything.
 *
 * It used to say that in this mode response code 20 *proves* a rejection. It
 * does not, and this file is where that correction is held. The observed
 * rejection was answered for an OrderID the merchant owned; what the gateway
 * answers for an OrderID it does not know is unobserved under both credential
 * states, which is the command's own argument for refusing to read a blind
 * success code — and it applies to a 20 in the same cell with the same force.
 * Reading the 20 as a refusal is an inference in the safe direction, and the
 * sentence now says which of the two it is.
 */
const BLIND_MODE_NOTE = 'Mode: blind. No --order-id value was given, so the probe asks about sentinel OrderID '
    .'-999999999, which no merchant can have registered. This mode can only detect a rejection: response code 20 '
    .'is read as a refusal, and the gateway\'s own message is printed with it, though only a known OrderID has ever '
    .'been observed being answered 20. No other answer proves anything either way, because what the gateway replies '
    .'for an OrderID it does not know has never been observed under correct or under incorrect credentials. Re-run '
    .'with --order-id set to an order you registered for an answer that can prove the credentials valid.';

/**
 * What the --order-id mode tells the merchant before it sends anything.
 */
const ORDER_MODE_NOTE = 'Mode: --order-id. The probe asks about OrderID 774312, which you have told this command '
    .'you registered. This mode can settle the question in both directions: a success code proves the credentials '
    .'authenticated against an order this merchant owns, and response code 20 proves they were refused.';

beforeEach(function (): void {
    vposConfig();
});

/**
 * The same outcome asserted in both modes, for every row the table calls
 * "either".
 *
 * Each case carries the `--order-id` value that selects the mode — null for the
 * blind probe — and the OrderID that mode ends up probing with, so a test can
 * assert the announced OrderID without knowing which mode it is in.
 */
dataset('both modes', [
    'blind' => [null, SENTINEL_ORDER_ID],
    '--order-id' => [(string) MERCHANT_ORDER_ID, MERCHANT_ORDER_ID],
]);

/**
 * The artisan parameters for a mode, from the option value that names it.
 *
 * A dataset hands its values to a closure untyped, so the mode travels as the
 * `--order-id` value itself — null for the blind probe — and is turned into an
 * option array here, where the shape can be declared once and checked.
 *
 * @return array<string, mixed>
 */
function checkParameters(?string $orderIdOption): array
{
    return $orderIdOption === null ? [] : ['--order-id' => $orderIdOption];
}

/**
 * Runs `vpos:check` with $client answering the single exchange it makes.
 *
 * Artisan::call rather than the artisan() test helper, because the whole
 * output is needed as a string: many of the assertions below are about what is
 * *not* in it, and a fluent expectation that can only look for what is there
 * cannot make them.
 *
 * Option values are passed as strings because that is what a shell gives a
 * console command. Passing an int would exercise a path no real invocation can
 * reach, and would silently take the blind branch.
 *
 * ## Why the stub's own refusal is re-raised here
 *
 * StubHttpClient scripts one exchange and throws on a second, so that a retry
 * or a loop is a finding rather than something absorbed silently. handle() now
 * ends in a `catch (Throwable)` clause, which catches that refusal along with
 * everything else and turns it into exit 2 and an "unexpected RuntimeException"
 * line — an outcome several tests below assert on their own account. The stub's
 * refusal would therefore have become a silent pass. It is recovered out of
 * band, from the request log the stub writes before it throws, because the
 * exception itself can no longer get out.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{exit: int, output: string}
 */
function runCheck(?ClientInterface $client = null, array $parameters = []): array
{
    if ($client instanceof ClientInterface) {
        app()->instance(ClientInterface::class, $client);
    }

    $exit = Artisan::call('vpos:check', $parameters);
    $output = Artisan::output();

    if ($client instanceof StubHttpClient && count($client->requests()) > 1) {
        throw new RuntimeException(sprintf(
            'The command asked the stub for %d exchanges. It scripts one, and a second call means vpos:check '
            .'retried or looped — one probe is the whole contract, and a retry would register the sentinel '
            .'OrderID against the gateway twice. The stub refused the second call, but handle()\'s terminal '
            .'catch (Throwable) swallowed the refusal and returned exit 2, so this is raised from the request '
            .'log instead of being lost.',
            count($client->requests()),
        ));
    }

    return ['exit' => $exit, 'output' => $output];
}

/**
 * No credential leaves this command, in any outcome.
 *
 * The password is asserted absent whole, and so are the ClientID and the
 * username: the command prints their first four characters, so the full value
 * appearing anywhere means the masking was skipped or unwound.
 */
function expectNoCredentialLeak(string $output): void
{
    expect($output)->not->toContain('PASSWORD-NOT-A-REAL-CREDENTIAL')
        ->and($output)->not->toContain('CLIENTID-NOT-A-REAL-CREDENTIAL')
        ->and($output)->not->toContain('USERNAME-NOT-A-REAL-CREDENTIAL');
}

/**
 * The PaymentID the gateway returned never reaches the output, in either mode.
 *
 * It belongs to a real order and this output goes into terminals and CI logs.
 * The assertion is made on the whole value and on a distinctive fragment of it,
 * so a truncated or reformatted echo is caught as well as a verbatim one.
 */
function expectNoPaymentIdLeak(string $output): void
{
    expect($output)->not->toContain(RETURNED_PAYMENT_ID)
        ->and($output)->not->toContain('ca7ce9d8');
}

/**
 * The lines every run prints once it has chosen a probe.
 *
 * $orderId is the OrderID the run announced — the sentinel in the blind mode,
 * the supplied one otherwise — so the announcement is checked against the mode
 * rather than against a constant that would be right for only one of them.
 */
function expectProbeHeader(string $output, int $orderId): void
{
    expect($output)
        ->toContain(NO_RELIABLE_CHECK)
        ->toContain('Environment: test')
        ->toContain('Base URL: https://servicestest.ameriabank.am/VPOS/')
        ->toContain('ClientID: CLIE...')
        ->toContain('Username: USER...')
        ->toContain('Password: (set)')
        ->toContain('BackURL: https://shop.example.test/vpos/back')
        ->toContain(sprintf('Sending one GetPaymentId request for OrderID %d.', $orderId));

    expectNoCredentialLeak($output);
}

/*
 * ---------------------------------------------------------------------------
 * The mode statement: a merchant reading exit 2 must be able to tell
 * "your credentials might be wrong" from "this probe cannot tell".
 * ---------------------------------------------------------------------------
 */

it('names the blind mode and says it can only ever detect a rejection', function (): void {
    $result = runCheck(StubHttpClient::answering(200, SUCCESS_BODY));

    expectProbeHeader($result['output'], SENTINEL_ORDER_ID);

    expect($result['output'])
        ->toContain(BLIND_MODE_NOTE)
        ->and($result['output'])->not->toContain('Mode: --order-id.');
});

it('names the --order-id mode and says it can settle the question in both directions', function (): void {
    $result = runCheck(
        StubHttpClient::answering(200, SUCCESS_BODY),
        ['--order-id' => (string) MERCHANT_ORDER_ID],
    );

    expectProbeHeader($result['output'], MERCHANT_ORDER_ID);

    expect($result['output'])
        ->toContain(ORDER_MODE_NOTE)
        ->and($result['output'])->not->toContain('Mode: blind.');
});

/*
 * The option takes an optional value, so a bare --order-id arrives
 * indistinguishable from the option being absent. Both are the blind probe, and
 * the wording the blind mode prints is true of both.
 */
it('treats a bare --order-id carrying no value as the blind probe', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub, ['--order-id' => null]);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])->toContain(BLIND_MODE_NOTE)
        ->and($stub->requests()[0]->getBody()->__toString())->toContain('"OrderID":-999999999');
});

/*
 * ---------------------------------------------------------------------------
 * The standing caveat is printed whatever the run goes on to find,
 * including on runs that never reach the gateway at all.
 * ---------------------------------------------------------------------------
 */

it('warns on every run that the gateway offers no reliable credential check', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(StubHttpClient::answering(200, SUCCESS_BODY), checkParameters($orderIdOption));

    expect($result['output'])->toContain(NO_RELIABLE_CHECK);
})->with('both modes');

it('warns that there is no reliable credential check even when nothing is sent', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($stub->requests())->toBe([])
        ->and($result['output'])->toContain(NO_RELIABLE_CHECK);
});

/*
 * ---------------------------------------------------------------------------
 * The success rows. These are the two cells the whole redesign turns on.
 * ---------------------------------------------------------------------------
 */

/*
 * THE REGRESSION GUARD. Do not "fix" this to expect 0.
 *
 * A success code for an OrderID the gateway does not know is not evidence that
 * the credentials are good. It is not evidence of anything: the reply to an
 * unknown OrderID has never been observed under correct credentials *or* under
 * incorrect ones, so there is nothing to compare it against. The command that
 * shipped before this one drew a verdict from an answer of exactly that kind,
 * and the consequence was that a merchant with a typo'd password got exit 0 and
 * deployed. Exit 2 is what "this probe cannot tell" looks like to a CI pipeline,
 * which reads the code and not the prose.
 *
 * Changing this expectation to 0 reinstates that defect. If a future observation
 * makes a blind success code meaningful, the evidence — not this assertion — is
 * what has to change first.
 */
it('exits 2 rather than 0 when a blind probe is answered with a success code', function (): void {
    $result = runCheck(StubHttpClient::answering(200, SUCCESS_BODY));

    expect($result['exit'])->toBe(
        2,
        'A blind probe answered with a success code must exit 2 (inconclusive), never 0. Exit 0 means the '
        .'credentials were PROVEN valid, and nothing proves that here: the gateway\'s reply to an OrderID it does '
        .'not know has never been observed under correct or under incorrect credentials. Reporting 0 is how a '
        .'merchant with a typo\'d password came to see a pass and deploy. Re-read the outcome table before '
        .'changing this number.',
    );

    expect($result['output'])
        ->toContain('Inconclusive. GetPaymentId answered with a success code for sentinel OrderID -999999999, '
            .'and that establishes nothing about the credentials: the reply to an OrderID the gateway does not '
            .'know has never been observed under correct or under incorrect credentials, so it is not evidence '
            .'in either direction. Re-run with --order-id set to an order you registered.')
        ->and($result['output'])->not->toContain('The credentials are valid.');

    expectNoPaymentIdLeak($result['output']);
    expectNoCredentialLeak($result['output']);
});

it('exits 0 only when a success code answers an order the merchant registered', function (): void {
    $result = runCheck(
        StubHttpClient::answering(200, SUCCESS_BODY),
        ['--order-id' => (string) MERCHANT_ORDER_ID],
    );

    expectProbeHeader($result['output'], MERCHANT_ORDER_ID);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('The credentials are valid. GetPaymentId answered with a success code for OrderID 774312, '
            .'so the gateway authenticated this ClientID, username and password and looked that order up under '
            .'them. The PaymentID it returned is deliberately not printed. This holds only if OrderID 774312 is '
            .'an order you registered under this ClientID — nothing here can check that, and if it is not, the '
            .'reply came from the one cell nothing has ever been observed in and means nothing. Re-run with an '
            .'order you own.')
        ->and($result['output'])->not->toContain('Inconclusive.')
        ->and($result['output'])->not->toContain(PRODUCTION_CAVEAT);

    expectNoPaymentIdLeak($result['output']);
});

/*
 * The other half of the production branch, and the reason it is a separate test.
 *
 * The verdict asks whether the resolved environment is production, and a branch
 * is pinned only when both of its answers are. The test above is the sandbox
 * side and asserts the caveat is absent; this one is the production side and
 * asserts it is present. Flip the comparison in the command and one of the two
 * goes red whichever way it is flipped; assert only this one and the flipped
 * command prints a production caveat on every sandbox run and still passes.
 *
 * What the caveat says is not decoration either. Neither production host has
 * ever returned a byte, so every observation behind "the credentials are valid"
 * was made against the sandbox pair. The exit code is still 0 — that is the
 * contract — but the operator is told the evidence does not cover the hosts
 * they have just been talking to.
 */
it('warns that a production verdict rests on observations no production host ever produced', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 'production']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub, ['--order-id' => (string) MERCHANT_ORDER_ID]);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('Environment: production')
        ->toContain('Base URL: https://services.ameriabank.am/VPOS/')
        ->toContain('The credentials are valid. GetPaymentId answered with a success code for OrderID 774312,')
        ->toContain(PRODUCTION_CAVEAT)
        ->and($stub->requests()[0]->getUri()->__toString())
        ->toBe('https://services.ameriabank.am/VPOS/api/VPOS/GetPaymentId');

    expectNoPaymentIdLeak($result['output']);
    expectNoCredentialLeak($result['output']);
});

/*
 * The no-echo rule, asserted where it can actually fail: the PaymentID exists in the reply
 * only when the reply succeeded, so both success cells are checked, and one
 * failure cell whose body also carries a PaymentId.
 */
it('never echoes the PaymentId the gateway returned', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(StubHttpClient::answering(200, SUCCESS_BODY), checkParameters($orderIdOption));

    expectNoPaymentIdLeak($result['output']);
    expectNoCredentialLeak($result['output']);
})->with('both modes');

it('never echoes a PaymentId that arrived alongside a failure code', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(StubHttpClient::answering(200, OTHER_CODE_BODY), checkParameters($orderIdOption));

    expect($result['exit'])->toBe(2);

    expectNoPaymentIdLeak($result['output']);
    expectNoCredentialLeak($result['output']);
})->with('both modes');

/*
 * ---------------------------------------------------------------------------
 * The rejection row: exit 1, in both modes, in both wire forms of code 20.
 * ---------------------------------------------------------------------------
 */

it('exits 1 when the gateway answers response code 20 as an integer', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(
        StubHttpClient::answering(200, '{"ResponseCode":20,"ResponseMessage":"Incorrect Username and Password"}'),
        checkParameters($orderIdOption),
    );

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: Incorrect Username and Password'.REJECTION_ADVICE)
        ->and($result['output'])->not->toContain('Inconclusive.');
})->with('both modes');

/*
 * The string form is the one the gateway actually returned on this endpoint,
 * and the one the client deliberately declines to classify — it becomes a plain
 * ApiException. This command reads it as a rejection anyway, because it asks
 * exactly one endpoint and that is the endpoint where the string form was
 * observed carrying "Incorrect Username and Password".
 */
it('exits 1 when the gateway answers response code 20 as a string', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(
        StubHttpClient::answering(200, '{"ResponseCode":"20","ResponseMessage":"Incorrect Username and Password"}'),
        checkParameters($orderIdOption),
    );

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: Incorrect Username and Password'.REJECTION_ADVICE)
        ->and($result['output'])->not->toContain('Inconclusive.');
})->with('both modes');

it('says a rejection carried no message when the gateway sent none', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":20}'));

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: (no message)'.REJECTION_ADVICE);

    expectNoCredentialLeak($result['output']);
});

/*
 * ---------------------------------------------------------------------------
 * The inconclusive rows. Every one of them is exit 2, in both modes.
 * ---------------------------------------------------------------------------
 */

/*
 * The row the previous command got wrong in the other direction: it read a
 * fault envelope as proof the credentials had been accepted.
 *
 * The wording is as much the subject of this assertion as the exit code. The
 * general rule — that a caller can infer nothing about its credentials from a
 * fault — is what carries the row to exit 2, and it holds without qualification.
 * The paired observation behind that rule was made on GetPaymentDetails, and no
 * fault from GetPaymentId has ever been observed at all. This message used to
 * say "a wrong password has been observed producing the same fault" naming no
 * endpoint, which a merchant reads as a claim about the request that has just
 * run. Moving an observation from the cell it was made in to a neighbouring one
 * is exactly what produced the defect this command was rewritten to remove, so
 * the two claims are now kept apart and the assertion pins the separation.
 *
 * The closing clause is pinned with them: a merchant who reads exit 2 needs
 * somewhere to go next, and a fault points at the bank rather than at their
 * configuration.
 */
it('exits 2 on a gateway fault, keeping the general rule and the endpoint it was observed on apart', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(StubHttpClient::answering(500, '{"Message":"An error has occurred."}'), checkParameters($orderIdOption));

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. The gateway answered with a fault envelope rather than a response code, so it '
            .'never reached a credential verdict this command could read. A fault is not a credential verdict '
            .'either way: on the one endpoint where that has been studied, a wrong password produced the same '
            .'fault as correct credentials, and no fault from GetPaymentId has ever been observed. GetPaymentId '
            .'returned a gateway fault (HTTP 500) carrying no response code, so the request was not answered; do '
            .'not retry it. The gateway reported: An error has occurred. Re-run; if it persists, report the '
            .'operation, the time and the environment to Ameriabank — nothing here points at your configuration.')
        ->and($result['output'])->not->toContain('The credentials are valid.');
})->with('both modes');

/*
 * The 550 row. The client records that code arriving from a wrong password and
 * from correct credentials alike — on GetPaymentDetails, the one endpoint where
 * the question has been put — so it distinguishes nothing and gets no branch of
 * its own here. This test was named for that observation as though it had been
 * made on the probe this command actually sends. It had not, and the name now
 * says only what the command can act on: 550 is not the rejection code, so it
 * settles nothing.
 */
it('exits 2 on response code 550, because it is not the rejection code and so settles nothing', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(
        StubHttpClient::answering(200, '{"ResponseCode":"550","ResponseMessage":"System Error"}'),
        checkParameters($orderIdOption),
    );

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. GetPaymentId answered with response code 550: System Error. That is neither a '
            .'success code nor the rejection code 20, so it establishes nothing about the credentials. Quote that '
            .'code and the gateway\'s message to Ameriabank; this package deliberately ships no code table, '
            .'because the codes it has seen are overloaded and a local table would have had to guess which '
            .'meaning applied.')
        ->and($result['output'])->not->toContain('The credentials are valid.');
})->with('both modes');

it('exits 2 on any other response code, reporting the code and the gateway message', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(
        StubHttpClient::answering(200, '{"ResponseCode":"07","ResponseMessage":"Payment amount exceeds"}'),
        checkParameters($orderIdOption),
    );

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. GetPaymentId answered with response code 07: Payment amount exceeds. That is '
            .'neither a success code nor the rejection code 20, so it establishes nothing about the credentials. '
            .'Quote that code and the gateway\'s message to Ameriabank; this package deliberately ships no code '
            .'table, because the codes it has seen are overloaded and a local table would have had to guess '
            .'which meaning applied.');
})->with('both modes');

it('says an answer carried no message when the gateway sent none', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":"550","Description":"System Error"}'));

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. GetPaymentId answered with response code 550: (no message). That is neither a '
            .'success code nor the rejection code 20, so it establishes nothing about the credentials. Quote that '
            .'code and the gateway\'s message to Ameriabank; this package deliberately ships no code table, '
            .'because the codes it has seen are overloaded and a local table would have had to guess which '
            .'meaning applied.');

    expectNoCredentialLeak($result['output']);
});

it('exits 2 when the gateway could not be reached, because nothing was learned', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(StubHttpClient::failingWith(new StubClientException('the socket timed out')), checkParameters($orderIdOption));

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. Could not reach the gateway, so nothing was learned about the credentials. '
            .'The GetPaymentId request could not be completed: '.StubClientException::class.' Check network '
            .'egress from this host to the base URL printed above — a proxy, an egress allowlist and DNS are '
            .'the usual three — and re-run.')
        ->and($result['output'])->not->toContain('The credentials are valid.');
})->with('both modes');

/*
 * A reply this client cannot parse proves nothing about credentials, and none
 * of it may be printed: the raw body is unvalidated remote content that a
 * gateway could have filled with anything, and this output goes to terminals
 * and log files.
 */
it('exits 2 on an unreadable reply and prints none of the raw body', function (?string $orderIdOption, int $orderId): void {
    $result = runCheck(
        StubHttpClient::answering(200, '{"ResponseCode": UNPARSEABLE-BODY-MUST-NOT-BE-PRINTED}'),
        checkParameters($orderIdOption),
    );

    expectProbeHeader($result['output'], $orderId);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. The exchange did not produce a reply this command could read, so nothing about '
            .'the credentials was established — neither that they work nor that they do not. Nothing of the reply '
            .'itself is printed: it is unvalidated remote content. The GetPaymentId response was not valid JSON: '
            .'JsonException Re-run; if it persists, report the operation, the time and the environment to '
            .'Ameriabank — nothing here points at your configuration.')
        ->and($result['output'])->not->toContain('UNPARSEABLE-BODY-MUST-NOT-BE-PRINTED');
})->with('both modes');

/*
 * Well-formed JSON of a shape the client refuses. This is the same exception
 * class arriving from the hydrator rather than from the decoder, and it is the
 * shape the old success fixture had: a body carrying a success code but no
 * ResponseMessage is not a GetPaymentId response.
 */
it('exits 2 when a success code arrives in a shape this client cannot hydrate', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":"00"}'));

    expect($result['exit'])->toBe(2)
        ->and($result['output'])
        ->toContain('Inconclusive. The exchange did not produce a reply this command could read')
        ->toContain('The GetPaymentId response had an unexpected shape: the required ResponseMessage field was '
            .'absent, null, or not text Re-run; if it persists, report the operation, the time and the '
            .'environment to Ameriabank — nothing here points at your configuration.')
        ->and($result['output'])->not->toContain('The credentials are valid.');
});

/*
 * ---------------------------------------------------------------------------
 * The configuration rows: exit 1, because a configuration this package refuses
 * is a fact about the merchant's configuration and not a guess about it.
 * ---------------------------------------------------------------------------
 */

it('refuses an unknown environment before it sends anything', function (?string $orderIdOption, int $orderId): void {
    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub, checkParameters($orderIdOption));

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('The Ameriabank vPOS configuration is not usable. Unknown Ameriabank vPOS environment '
            .'"staging". Set ameriabank-vpos.environment (AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.')
        ->and($result['output'])->not->toContain('Environment: ');

    expectNoCredentialLeak($result['output']);
})->with('both modes');

it('refuses a blank credential before it sends anything, and names the field', function (?string $orderIdOption, int $orderId): void {
    /*
     * Two of the three are absent rather than empty, which is what an
     * application that never set the environment variables actually has: the
     * config file's env() call yields null, and the command has to read that as
     * "not set" rather than printing whatever a non-string coerces to.
     */
    vposConfig([
        'ameriabank-vpos.client_id' => null,
        'ameriabank-vpos.username' => null,
        'ameriabank-vpos.password' => '',
    ]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub, checkParameters($orderIdOption));

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('ClientID: (not set)')
        ->toContain('Username: (not set)')
        ->toContain('Password: (not set)')
        ->toContain('The Ameriabank vPOS configuration is not usable. Credential field "ClientID" must not be blank.');
})->with('both modes');

/*
 * The credential lines are read through an is_string() test, not a cast, and
 * the difference is only visible on a value that is neither.
 *
 * Every other fixture here uses null for the absent case, and (string) null is
 * the empty string, so null cannot tell the two readings apart: both print
 * "(not set)". An int, a float and a bool can. A cast would print 1234... for
 * a ClientID that is a YAML-typed number, and "(set)" for a password that is
 * the boolean true — both of which are this command claiming to have read a
 * credential the client is about to refuse as blank.
 *
 * These are configuration mistakes an application really makes: an all-digit
 * ClientID unquoted in a .env-driven cast, or a value a merchant set to true
 * meaning "enabled".
 */
it('reads a non-string credential as absent rather than as whatever it coerces to', function (): void {
    vposConfig([
        'ameriabank-vpos.client_id' => 12345678,
        'ameriabank-vpos.username' => 4.5,
        'ameriabank-vpos.password' => true,
    ]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('ClientID: (not set)')
        ->toContain('Username: (not set)')
        ->toContain('Password: (not set)')
        ->toContain('The Ameriabank vPOS configuration is not usable. Credential field "ClientID" must not be blank.')
        ->and($result['output'])->not->toContain('1234')
        ->and($result['output'])->not->toContain('4.5')
        ->and($result['output'])->not->toContain('(set)');
});

it('refuses an unresolvable back_url before it sends anything', function (): void {
    Route::get('/checkout/vpos/back', static fn (): string => 'ok')->name('checkout.vpos.back');
    vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.bakc']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Password: (set)')
        ->and($result['output'])->not->toContain('BackURL: ')
        ->and($result['output'])->toContain('The Ameriabank vPOS configuration is not usable. ameriabank-vpos.back_url is '
            .'"checkout.vpos.bakc", which is neither an absolute http or https URL nor the name of a registered '
            .'route. Name a route, or configure a full URL.');

    expectNoCredentialLeak($result['output']);
});

/*
 * The core's ValidationException. It reaches this command from exactly one
 * configuration key, so the command names that key and the environment variable
 * behind it rather than letting an InvalidArgumentException escape as an
 * unrendered stack trace.
 */
it('refuses an out-of-range max_attempts and names the configuration key', function (?string $orderIdOption, int $orderId): void {
    vposConfig(['ameriabank-vpos.max_attempts' => 9]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub, checkParameters($orderIdOption));

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('The Ameriabank vPOS configuration is not usable. ameriabank-vpos.max_attempts '
            .'(AMERIABANK_VPOS_MAX_ATTEMPTS) reached the client as 9, and the client refused it. '
            .'The maximum attempt count must be between 1 and 5, got 9.');

    expectNoCredentialLeak($result['output']);
})->with('both modes');

/*
 * A non-integer max_attempts reaches the client as 0, and 0 is what the message
 * must report: the configured text is not echoed back, because what decides the
 * refusal is the value the client was actually handed.
 */
it('reports a non-integer max_attempts as the zero the client was handed', function (): void {
    vposConfig(['ameriabank-vpos.max_attempts' => 'three']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) reached the client as 0, and the '
            .'client refused it. The maximum attempt count must be between 1 and 5, got 0.')
        ->and($result['output'])->not->toContain('three');
});

/*
 * ---------------------------------------------------------------------------
 * The usage row: a mistyped option is not an answer about the credentials.
 * ---------------------------------------------------------------------------
 */

it('refuses a non-integer --order-id, sends nothing, and exits 2', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub, ['--order-id' => 'A-77']);

    expect($result['exit'])->toBe(2)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('--order-id must be an integer OrderID, and "A-77" is not one. Nothing was sent to the '
            .'gateway. Pass the OrderID you gave InitPayment, or omit the option to run the blind probe.')
        ->and($result['output'])->not->toContain('Mode: ')
        ->and($result['output'])->not->toContain('Sending one GetPaymentId request');

    /*
     * This assertion used to be its own inverse.
     *
     * The caveat was emitted after the mode note, so this path — the one that
     * returns before a probe is chosen — was the single run that did not carry
     * it, and the absence was pinned here on the reasoning that nothing had
     * been sent so there was no result to caveat. That reasoning is defensible
     * and it is the wrong side of the tie-breaker: suppressing the sentence
     * preserves less, and the run that most needs it is the one where the
     * operator is still working out what the command can answer. "Printed on
     * every run" now means every run.
     */
    expect($result['output'])->toContain(NO_RELIABLE_CHECK);
});

/*
 * The one value this command prints that the operator typed, and the only one
 * that can carry console markup.
 *
 * Every line goes through Symfony's output formatter, which reads `<...>` as
 * styling instructions rather than as text. Without the escape the formatter
 * eats the tags, and the message reports the value as "7" — a value the
 * operator never typed, in the one message whose entire job is to show them
 * what they did type. A value that restyles the message around it has been
 * interpreted rather than reported.
 *
 * Both a closing and an opening tag are used, because they are consumed by
 * different branches of the formatter and an escape that handled one and not
 * the other would still read as working.
 */
it('reproduces a --order-id carrying console markup literally instead of interpreting it', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub, ['--order-id' => '<fg=red>7</>']);

    expect($result['exit'])->toBe(2)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('--order-id must be an integer OrderID, and "<fg=red>7</>" is not one. Nothing was sent to '
            .'the gateway.')
        ->and($result['output'])->not->toContain('and "7" is not one');
});

/*
 * ---------------------------------------------------------------------------
 * The probe itself: the operation, and the OrderID each mode puts on the wire.
 * ---------------------------------------------------------------------------
 */

/*
 * The premise underneath the max_attempts message, pinned where it can break.
 *
 * Every core ValidationException reaching the command is reported as a problem
 * with ameriabank-vpos.max_attempts, on the reasoning that the attempt budget is
 * the only configured value this exchange hands the client that the client
 * range-checks. That reasoning is about a separate package on its own release
 * cycle, and it is held by nothing but having read the client's call sites once.
 *
 * The value most likely to falsify it is this command's own sentinel. It is
 * negative and nine digits precisely so that no merchant could own it, and the
 * client's OrderID carries no range check today. If the client ever adds one, a
 * `composer update` turns the blind probe into a run that refuses itself before
 * the wire and then tells the merchant to go and look at an environment
 * variable that had nothing to do with it — first sentence wrong, and it is the
 * sentence an operator acts on.
 *
 * This guard is that moment's alarm: the blind probe must reach the wire
 * carrying the sentinel, and no blind run may name the attempt-budget key. It
 * pins the premise rather than the branch, because the branch does not exist —
 * see the note this file's max_attempts tests carry.
 */
it('keeps the sentinel OrderID one the client will actually send, so no refusal of it can be mislabelled', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub);

    expect(count($stub->requests()))->toBe(1, 'The blind probe never reached the wire. If the client now refuses '
        .'the sentinel OrderID -999999999, vpos:check reports that refusal as a problem with '
        .'ameriabank-vpos.max_attempts, which is the one ValidationException it assumes it can receive. Make '
        .'attemptBudgetRefused() compare the exception against ValidationException::maxAttemptsOutOfRange() with '
        .'the configured value, and give anything unmatched a generic refusal that still exits 1 and still '
        .'prints the client\'s own words.')
        ->and($stub->requests()[0]->getBody()->__toString())->toContain('"OrderID":'.SENTINEL_ORDER_ID)
        ->and($result['output'])->not->toContain('AMERIABANK_VPOS_MAX_ATTEMPTS');
});

it('sends the blind probe to GetPaymentId carrying the sentinel OrderID', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    runCheck($stub);

    $requests = $stub->requests();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->getMethod())->toBe('POST')
        ->and($requests[0]->getUri()->__toString())
        ->toBe('https://servicestest.ameriabank.am/VPOS/api/VPOS/GetPaymentId')
        ->and($requests[0]->getBody()->__toString())
        ->toContain('"OrderID":-999999999');
});

it('sends the --order-id probe to GetPaymentId carrying the OrderID it was given', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    runCheck($stub, ['--order-id' => (string) MERCHANT_ORDER_ID]);

    $requests = $stub->requests();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->getUri()->__toString())
        ->toBe('https://servicestest.ameriabank.am/VPOS/api/VPOS/GetPaymentId')
        ->and($requests[0]->getBody()->__toString())
        ->toContain('"OrderID":774312')
        ->and($requests[0]->getBody()->__toString())->not->toContain('-999999999');
});
