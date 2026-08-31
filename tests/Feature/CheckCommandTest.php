<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Commands\CheckCommand;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubClientException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubHttpClient;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
 * The five branches that can only report an inconclusive outcome, and the
 * sentence each of them opens with.
 *
 * Keyed by the private method that produces the row, so a case name in the
 * output names the branch under test. The value is enough of the branch's own
 * message to tell it apart from the other four; the whole message is pinned
 * elsewhere in this file, and repeating it here would put the same literal in
 * two places and make a wording change red twice for one reason.
 *
 * `blindSuccess()` and `unusableOrderId()` are the two exit-2 rows that are not
 * here, and both are deliberate. `blindSuccess()` can only ever happen in the
 * blind mode, so a mode-dependent clause would be an unconditional one wearing
 * a disguise, and it names the option in its own message already.
 * `unusableOrderId()` returns before a mode is chosen at all — there is no
 * blind run for it to point out — and it, too, names the option itself.
 *
 * @return array<string, string>
 */
function inconclusiveBranches(): array
{
    return [
        'apiAnswer' => 'Inconclusive. GetPaymentId answered with response code 07',
        'faulted' => 'Inconclusive. The gateway answered with a fault envelope rather than a response code',
        'unexpected' => 'Inconclusive. The run failed with an unexpected RuntimeException',
        'unreachable' => 'Inconclusive. Could not reach the gateway, so nothing was learned about the credentials.',
        'unreadable' => 'Inconclusive. The exchange did not produce a reply this command could read',
    ];
}

/**
 * A fresh PSR-18 client that drives `vpos:check` into one named branch.
 *
 * Built per call rather than held in the dataset, because StubHttpClient
 * scripts a single exchange and refuses a second: a client shared between the
 * blind case and the --order-id case would make the second run fail for a
 * reason that has nothing to do with what is being asserted.
 *
 * The default arm refuses rather than falling back to some other client. A
 * branch named in the table with no way to provoke it would otherwise be
 * asserted against a run that never entered it, which passes for the wrong
 * reason; the refusal names the branch instead.
 */
function inconclusiveBranchClient(string $branch): ClientInterface
{
    return match ($branch) {
        'apiAnswer' => StubHttpClient::answering(200, '{"ResponseCode":"07","ResponseMessage":"Payment amount exceeds"}'),
        'faulted' => StubHttpClient::answering(500, '{"Message":"An error has occurred."}'),
        'unexpected' => clientRaisingUnclassifiedFailure(),
        'unreachable' => StubHttpClient::failingWith(new StubClientException('the socket timed out')),
        'unreadable' => StubHttpClient::answering(200, '{"ResponseCode": UNPARSEABLE-BODY-MUST-NOT-BE-PRINTED}'),
        default => throw new RuntimeException(sprintf(
            'inconclusiveBranches() names the "%s" branch and nothing here can provoke it, so the pointer would '
            .'have been asserted against a run that never entered it.',
            $branch,
        )),
    };
}

/**
 * A PSR-18 client raising something no clause in `handle()` names.
 *
 * `HttpTransport::dispatch()` wraps only the three PSR-18 interfaces, so a
 * plain RuntimeException travels the whole way up unwrapped and arrives at the
 * terminal `catch (Throwable)`. That is the one route left into it, and it is
 * the only way to reach `unexpected()` from a test.
 */
function clientRaisingUnclassifiedFailure(): ClientInterface
{
    return new readonly class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('the stand-in for a component this command does not classify');
        }
    };
}

/**
 * Every branch `handle()` hands the run's mode to, derived from the class.
 *
 * The table above is a list of five method names, and a list of names goes
 * stale the moment a sixth branch is written. This reads the signatures
 * instead: a private handler that returns an exit code and takes a boolean is
 * one `handle()` has told whether the run was blind, and there is nothing else
 * in this class shaped like that. Declared methods only, so nothing inherited
 * from Symfony's or Laravel's Command can wander in.
 *
 * It is a proxy rather than a proof — it cannot see what a branch does with the
 * flag — and the two tests below are what establish that. What it catches is
 * the failure the table cannot: a sixth branch given the mode and never
 * asserted, which would leave this file passing while the new row printed
 * whatever it liked.
 *
 * @return list<string>
 */
function checkCommandBranchesToldTheMode(): array
{
    $subjects = [];

    foreach ((new ReflectionClass(CheckCommand::class))->getMethods() as $method) {
        $returnType = $method->getReturnType();

        if ($method->getDeclaringClass()->getName() !== CheckCommand::class) {
            continue;
        }

        if (! $returnType instanceof ReflectionNamedType || $returnType->getName() !== 'int') {
            continue;
        }

        foreach ($method->getParameters() as $parameter) {
            $parameterType = $parameter->getType();

            if ($parameterType instanceof ReflectionNamedType && $parameterType->getName() === 'bool') {
                $subjects[] = $method->getName();

                break;
            }
        }
    }

    sort($subjects);

    return $subjects;
}

/**
 * One case per inconclusive branch, named after the branch.
 */
dataset('inconclusive branches', function (): array {
    $cases = [];

    foreach (array_keys(inconclusiveBranches()) as $branch) {
        $cases[$branch] = [$branch];
    }

    return $cases;
});

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

    /*
     * The sixth inconclusive row, and the one the blind pointer is kept off.
     *
     * `pointBlindRunAtOrderId()`'s docblock excludes it and nothing observed
     * the exclusion. It is reachable in no other mode, so a clause conditional
     * on the mode would be an unconditional one wearing a disguise, and the
     * message asserted above already ends by naming the option. This pins that
     * reasoning against the one mutation the tooling cannot make: mutants
     * remove and invert calls, they never add one.
     */
    expect($result['output'])->not->toContain(blindPointer());

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

    /*
     * A rejection carries no blind pointer, and the blind case of this dataset
     * is where that can go wrong.
     *
     * `pointBlindRunAtOrderId()`'s docblock excludes the rejection because a
     * rejection is an answer and the mode does not change it. The failure
     * direction is the bad one: the pointer says no answer this run could have
     * received would have proved the credentials valid, and appending it to
     * the one row where the gateway did give a verdict about them contradicts
     * the line above it.
     *
     * Integer 20 arrives as an AuthenticationException and is classified in
     * `handle()` itself, so what this pins is the call being hoisted into that
     * catch clause or after the try/catch. The string form reaches the same
     * branch by another route and is pinned separately below.
     */
    expect($result['output'])->not->toContain(blindPointer());
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

    /*
     * The same exclusion, on the other route into the same branch.
     *
     * The string form is not classified in `handle()`. It arrives as a plain
     * ApiException, so it reaches `apiAnswer()` — which *is* handed the mode —
     * and returns through the rejection test before the pointer is printed.
     * That makes this a different subject from the assertion above: it pins the
     * call being lifted over `apiAnswer()`'s rejection test, which would put
     * the pointer on a rejection with every other assertion in this file still
     * green.
     */
    expect($result['output'])->not->toContain(blindPointer());
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
 * The blind pointer: an inconclusive run in the mode that could not have
 * proved anything is told which mode could have.
 *
 * Each of these branches already ends with a next step for the condition it
 * reports, and each of those is asserted whole elsewhere in this file. The
 * pointer is additional and never a replacement, so both assertions are made on
 * the same run: the branch's own sentence and the clause after it.
 *
 * The mode note carrying the same advice is printed before the send, above the
 * six configuration lines and the "Sending one GetPaymentId request" line. The
 * package's own argument for repeating a premise in the verdict — proven()'s
 * docblock, "rather than leaving it four lines up the scrollback" — is stronger
 * here than in the row it was written for.
 * ---------------------------------------------------------------------------
 */

it('points a blind run at --order-id from every inconclusive branch', function (string $branch): void {
    $result = runCheck(inconclusiveBranchClient($branch));

    expect($result['exit'])->toBe(2)
        ->and($result['output'])->toContain(inconclusiveBranches()[$branch])
        ->and($result['output'])->toContain(blindPointer());
})->with('inconclusive branches');

it('omits the --order-id pointer from every inconclusive branch when --order-id was given', function (string $branch): void {
    $result = runCheck(inconclusiveBranchClient($branch), checkParameters((string) MERCHANT_ORDER_ID));

    expect($result['exit'])->toBe(2)
        ->and($result['output'])->toContain(inconclusiveBranches()[$branch])
        ->and($result['output'])->not->toContain(blindPointer());
})->with('inconclusive branches');

/*
 * The two lists above and below the pointer tests must be the same list.
 *
 * inconclusiveBranches() is written down; the branches the command actually
 * hands the mode to are read off its signatures. A sixth branch added and
 * given the flag would otherwise print an unasserted clause, or none, with
 * every test in this file still green.
 */
it('asserts the pointer on every branch the command hands the mode to', function (): void {
    $asserted = array_keys(inconclusiveBranches());
    sort($asserted);

    expect(checkCommandBranchesToldTheMode())->toBe($asserted, sprintf(
        'CheckCommand hands the run\'s mode to [%s], and this file asserts the blind pointer on [%s]. A branch '
        .'told whether the run was blind and never asserted either prints a clause nobody has read or silently '
        .'stops printing it. Add it to inconclusiveBranches() with a way to provoke it in '
        .'inconclusiveBranchClient(), or take the flag off it.',
        implode(', ', checkCommandBranchesToldTheMode()),
        implode(', ', $asserted),
    ));
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
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. Unknown Ameriabank vPOS environment "staging". Set '
            .'ameriabank-vpos.environment (AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.')
        ->and($result['output'])->not->toContain('Environment: ');

    expectNoCredentialLeak($result['output']);
})->with('both modes');

/*
 * The environment nobody configured, which is a different mistake again.
 *
 * `null` is what a missing key and an unset environment variable both arrive
 * as, and it really is absent — so it is read as blank and refused by the
 * factory that names the accepted values, not by the one that names a type.
 * The wrong-type refusal is asserted absent to keep the two apart: a value that
 * is not there has no type to report, and reporting one would send the operator
 * looking for a value to correct rather than a value to write.
 */
it('refuses an environment that was never configured, reading it as blank', function (): void {
    vposConfig(['ameriabank-vpos.environment' => null]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. Unknown Ameriabank vPOS environment "". Set ameriabank-vpos.environment '
            .'(AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.')
        ->and($result['output'])->not->toContain('must be a string, and the configured value is of type');

    expectNoCredentialLeak($result['output']);
});

it('refuses a blank credential before it sends anything, and names the field', function (?string $orderIdOption, int $orderId): void {
    /*
     * Three keys, three different routes to the same "(not set)".
     *
     * `client_id` is the empty string — a variable that is set and empty, which
     * is what a `.env` line with nothing after the `=` produces. `username` is
     * absent, which is what an application that never wrote the line has: the
     * config file's `env()` call yields null. `password` is empty as well, and
     * goes through `presence()` rather than `masked()`.
     *
     * They are deliberately not all the same shape. Absent and empty arrive at
     * the placeholder by different branches — one through the null reading and
     * one through the blank one — and a fixture using only null would leave the
     * blank branch printing whatever it liked.
     */
    vposConfig([
        'ameriabank-vpos.client_id' => '',
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
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. Credential field "ClientID" must not be blank.');
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
 *
 * ## What changed, and why "(not set)" is now asserted absent
 *
 * These three lines used to print "(not set)", which was the *cast* answer
 * wearing the absent one's clothes: the value is there, the operator wrote it
 * down, and the command sent them to look for a missing one. Each line now
 * says the key holds something of the wrong type and says nothing else about
 * it — "(not a string)" and "(set)" disclose exactly the same amount, which is
 * that the key is not empty.
 *
 * "(not set)" is therefore asserted **absent from the whole run**. It is the
 * one assertion here that would still pass if the distinction were dropped
 * again, so it is the one that holds the change: the fixture sets all three
 * keys, so a run printing "(not set)" anywhere has lost it. The configured
 * values stay asserted absent for the reason they always were.
 *
 * The refusal is the provider's, not the client's, and it names the key and
 * the type. The client's blank-credential refusal is asserted absent with it:
 * reaching that message again would mean a wrong-typed value had been read as
 * an empty one somewhere upstream.
 */
it('reports a non-string credential as the wrong type it is, never as absent', function (): void {
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
        ->toContain('ClientID: (not a string)')
        ->toContain('Username: (not a string)')
        ->toContain('Password: (not a string)')
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. ameriabank-vpos.client_id must be a '
            .'string, and the configured value is of type int. It is not missing — it is set to the wrong type, '
            .'and this package refuses it rather than casting it, because a cast would turn a misconfigured '
            .'value into a silently different one. Neither the value nor any part of it is repeated here, '
            .'because these keys can hold credentials. Correct the type in config/ameriabank-vpos.php, or in '
            .'the environment variable that key reads.')
        ->and($result['output'])->not->toContain('(not set)')
        ->and($result['output'])->not->toContain('1234')
        ->and($result['output'])->not->toContain('4.5')
        ->and($result['output'])->not->toContain('must not be blank');

    expectNoCredentialLeak($result['output']);
});

/*
 * The same mistake on the one key that is not a credential, which is the only
 * place the command's own refusal can be observed.
 *
 * `CheckCommand::configString()` is read by `environment()` and by nothing
 * else: the three credential lines go through `masked()` and `presence()`,
 * which describe a value rather than use one and so print a placeholder. So
 * this is the run where the *command* raises the wrong-type refusal rather
 * than the provider, and it happens before a single detail line is printed.
 *
 * The old reading is asserted absent. `configString()` used to answer '' for
 * anything that was not a string, so a numeric environment reached
 * `Environment::tryFrom('')` and produced a refusal about a blank environment
 * nobody had configured — the exact sentence a regression would print again.
 */
it('refuses an environment set to the wrong type before it prints anything about it', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 42]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. ameriabank-vpos.environment must be a '
            .'string, and the configured value is of type int. It is not missing — it is set to the wrong type, '
            .'and this package refuses it rather than casting it, because a cast would turn a misconfigured '
            .'value into a silently different one. Neither the value nor any part of it is repeated here, '
            .'because these keys can hold credentials. Correct the type in config/ameriabank-vpos.php, or in '
            .'the environment variable that key reads.')
        ->and($result['output'])->not->toContain('Environment: ')
        ->and($result['output'])->not->toContain('Unknown Ameriabank vPOS environment ""');

    expectNoCredentialLeak($result['output']);
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
        ->and($result['output'])->toContain('Ameriabank vPOS is not set up correctly, and this run stopped '
            .'without reaching a verdict on the credentials. ameriabank-vpos.back_url is "checkout.vpos.bakc", '
            .'which is neither an absolute http or https URL nor the name of a registered route. Name a route, '
            .'or configure a full URL.');

    /*
     * A configuration refusal carries no blind pointer, and this run is blind.
     *
     * `pointBlindRunAtOrderId()`'s docblock excludes the configuration
     * refusals for the same reason it excludes the rejection: each is an
     * answer, and the mode does not change it. Here it is more than that —
     * nothing was sent, so there is no reply a second run in the other mode
     * would have improved on, and the pointer would be advice to re-run a
     * probe that never happened. This is the only observation of that
     * exclusion on a run that actually took the blind path into
     * `misconfigured()`.
     */
    expect($result['output'])->not->toContain(blindPointer());

    expectNoCredentialLeak($result['output']);
});

/*
 * The other `back_url` mistake, and it is reported as its own mistake.
 *
 * A route name that is registered but declares a required parameter is not a
 * route name that does not exist, and the command no longer collapses the two.
 * BackUrlResolver converts UrlGenerationException — which extends Exception
 * directly, so it was never an InvalidArgumentException and was never caught by
 * the clause next to it — through a second named factory, and the result
 * arrives at handle()'s ClientConfigurationException|ConfigurationException
 * clause: exit 1, naming the field.
 *
 * **This test previously pinned exit 2.** It recorded the terminal
 * catch (Throwable) outcome so that this move would read in a diff as a
 * deliberate behaviour change rather than as a silent one. It is the one exit
 * code task 003 moves, and this is where the move is written down.
 *
 * Exit 1 is the right code because the claim it makes here is the configuration
 * one, not the credential one: `misconfigured()` is what returned it, and every
 * other `back_url` refusal already exits 1 through the same handler.
 *
 * ## Three assertions carry the change, and each is derived rather than typed
 *
 * The message asserted is the factory's own, so a reworded message updates in
 * one place and this test follows it. The route-not-found message is built from
 * *its* factory and asserted absent, which is what pins that the two cases have
 * two messages: routing the parameterised case back through
 * unresolvableBackUrlRoute() turns that assertion red immediately, and no
 * literal fragment of either message is hand-copied here to drift. And the
 * framework class is asserted absent, because its appearance in the output is
 * exactly what escaping to the terminal clause looks like.
 *
 * `Password: (set)` pins that the run reached the credential block before it
 * failed; without it the test would still pass if the failure moved upstream.
 * `BackURL: ` is asserted absent because the command must not print a BackURL
 * it never obtained.
 *
 * The framework's own phrasing and the route's URI pattern are both asserted
 * absent, and that is a decision rather than an accident: the factory composes
 * its own sentence and chains the framework exception as the cause instead of
 * quoting it. See ConfigurationException::parameterisedBackUrlRoute().
 */
it('names a back_url route that needs parameters as the configuration mistake it is, and sends nothing', function (): void {
    Route::get('/checkout/{order}/vpos/back', static fn (): string => 'ok')->name('checkout.vpos.back');
    vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.back']);

    $routeNotFound = ConfigurationException::unresolvableBackUrlRoute(
        'checkout.vpos.back',
        new RuntimeException('the other mistake, for contrast only'),
    )->getMessage();

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Password: (set)')
        ->and($result['output'])->not->toContain('BackURL: ')
        ->and($result['output'])->not->toContain('Sending one GetPaymentId request')
        ->and($result['output'])->toContain(sprintf(
            'Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict on the '
            .'credentials. %s',
            ConfigurationException::parameterisedBackUrlRoute(
                'checkout.vpos.back',
                new RuntimeException('the cause, which is not printed'),
            )->getMessage(),
        ))
        ->and($result['output'])->not->toContain($routeNotFound)
        ->and($result['output'])->not->toContain(UrlGenerationException::class)
        ->and($result['output'])->not->toContain('Missing required parameter')
        ->and($result['output'])->not->toContain('{order}');

    expectNoCredentialLeak($result['output']);
});

/*
 * ---------------------------------------------------------------------------
 * The attempt budget, now refused on this side of the bridge.
 *
 * It used to reach the client, which range-checks it in HttpTransport's
 * constructor and raises its own ValidationException. That refusal names a
 * number and nothing else — the client has no idea where the number came from
 * — so this command recognised it by rebuilding the client's message from the
 * configured value and then printed a sentence naming the key. Correct, and
 * held up by a message string owned by a separately versioned package.
 *
 * `AmeriabankVposServiceProvider::maxAttempts()` refuses the value at the
 * `maxAttempts:` argument position of `new Vpos(...)`, which PHP evaluates
 * before entering the constructor. No Vpos and no HttpTransport exists when
 * the refusal is raised, so the client's exception is not pre-empted — it is
 * unreachable for this cause — and the refusal arrives at handle()'s
 * ClientConfigurationException|ConfigurationException clause like every other
 * configuration mistake.
 *
 * Both tests assert the client's own message for the same value **absent**,
 * built from the client's factory rather than quoted. That is the acceptance
 * criterion in the output: a run in which the client had refused the budget
 * would carry it.
 * ---------------------------------------------------------------------------
 */

it('refuses an out-of-range max_attempts and names the configuration key', function (?string $orderIdOption, int $orderId): void {
    $configured = 9;

    vposConfig(['ameriabank-vpos.max_attempts' => $configured]);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub, checkParameters($orderIdOption));

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) must be an '
            .'integer between 1 and 5, and the configured value is '
            .'outside that range. The value itself is not repeated here. This is the total number of attempts a '
            .'retryable operation gets; which operations may be retried at all is fixed by the client and is '
            .'not configurable.')
        ->and($result['output'])
        ->not->toContain(ValidationException::maxAttemptsOutOfRange($configured)->getMessage());

    /*
     * The refusal carries no blind pointer, and the blind case of this dataset
     * is the one that could carry it.
     *
     * `pointBlindRunAtOrderId()` excludes the configuration refusals: nothing
     * was sent, so there is no reply a second run in the other mode would have
     * improved on, and the identical refusal would arrive again.
     */
    expect($result['output'])->not->toContain(blindPointer());

    expectNoCredentialLeak($result['output']);
})->with('both modes');

/*
 * A non-integer budget reports the type it was configured as.
 *
 * It used to report `0` — the number `configInt()` produced for anything that
 * was not an integer, and a budget nobody had configured. `(int) 'three'` is 0
 * and `(int) '3.9'` is 3: the first names a value out of thin air and the
 * second would have silently run a different one. The configured text itself
 * is still not echoed back, so `three` is asserted absent alongside the `0`.
 *
 * The `0` is asserted through the **client's own factory**, built with it. The
 * literal that sat beside it — *"reached the client as 0"* — was a fragment of
 * `attemptBudgetRefused()`, the named branch task 005 deleted, and it has been
 * removed rather than kept: no code can emit those words again, so it could
 * never have failed. `maxAttemptsOutOfRange(0)` is the same claim made against
 * a message that still exists, and it is derived rather than quoted.
 */
it('reports a non-integer max_attempts as the type it was configured as', function (): void {
    vposConfig(['ameriabank-vpos.max_attempts' => 'three']);

    $stub = StubHttpClient::answering(200, SUCCESS_BODY);
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
            .'on the credentials. ameriabank-vpos.max_attempts (AMERIABANK_VPOS_MAX_ATTEMPTS) must be an '
            .'integer between 1 and 5, and the configured value is of '
            .'type string, which this package refuses rather than casting, because a cast would run an attempt '
            .'budget nobody configured. The value itself is not repeated here. This is the total number of '
            .'attempts a retryable operation gets; which operations may be retried at all is fixed by the '
            .'client and is not configurable.')
        ->and($result['output'])->not->toContain('three')
        ->and($result['output'])->not->toContain(ValidationException::maxAttemptsOutOfRange(0)->getMessage())
        ->and($result['output'])->not->toContain('The Ameriabank vPOS client refused an input');
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
 * The sixth exit-2 row, and the only one that must not carry the blind pointer.
 *
 * `pointBlindRunAtOrderId()`'s docblock makes this claim in words — "Not
 * printed by unusableOrderId() either — that path returns before any mode is
 * chosen and names the option itself" — and nothing observed it. The claim
 * above pins that no *mode note* is printed; this pins the clause that hangs
 * off the mode, which is a separate statement in a separate method and could
 * start being printed here without that assertion moving.
 *
 * It matters in the direction the pointer is wrong. This path is reached only
 * when `--order-id` was given, so a pointer printed here would tell an operator
 * who has just typed `--order-id` to re-run with `--order-id`, in the one
 * message whose whole job is to show them what they mistyped. The run is also
 * not blind in the sense the clause means: nothing was sent, so no answer was
 * received that a different mode would have improved on.
 */
it('adds no blind pointer to the unusable --order-id refusal, which returns before a mode is chosen', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub, ['--order-id' => 'A-77']);

    expect($result['exit'])->toBe(2)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])->not->toContain(blindPointer());
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
 * The premise is that the blind probe reaches the wire, and it is a premise
 * about a separately versioned package: the client's OrderID carries no range
 * check today, and nothing but having read its call sites once holds that.
 *
 * The value most likely to falsify it is this command's own sentinel. It is
 * negative and nine digits precisely so that no merchant could own it. If the
 * client ever range-checks OrderID, a `composer update` turns the blind probe
 * into a run that refuses itself before the wire — and the refusal arrives as a
 * `ValidationException` about a value the merchant never configured, printed by
 * a branch that can say nothing about where it came from.
 *
 * This guard is that moment's alarm, and it survives task 005's move of the
 * attempt budget to the bridge unchanged. What it pins is unchanged too: the
 * blind probe must reach the wire carrying the sentinel, and no blind run may
 * name the attempt-budget environment variable. The second half was written
 * when there was a branch that could name it; it is kept because it is now the
 * standing statement that **no** validation refusal names a key, asserted from
 * the one run that sends something.
 */
it('keeps the sentinel OrderID one the client will actually send, so no refusal of it can be mislabelled', function (): void {
    $stub = StubHttpClient::answering(200, SUCCESS_BODY);

    $result = runCheck($stub);

    expect(count($stub->requests()))->toBe(1, 'The blind probe never reached the wire. The likeliest cause is '
        .'that the client now range-checks OrderID and refused the sentinel -999999999, in which case every '
        .'blind run raises a ValidationException before sending and vpos:check reports it through the generic '
        .'refusal — honest, but it says nothing an operator can act on and it exits 1, which in this command '
        .'means the credentials were proven wrong. Choose a sentinel the client will send, or give the refusal '
        .'of the sentinel a named branch of its own that says the probe could not be made rather than that the '
        .'configuration is at fault.')
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
