<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubClientException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubHttpClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Psr\Http\Client\ClientInterface;

/**
 * What the command says when the exchange completed and settled nothing.
 *
 * Written out here rather than read from the command, because a caveat that
 * changes whenever the code changes is not a caveat.
 */
const AMBIGUITY_NOTE = 'The request reached the gateway and was answered, which is what a working '
    .'configuration looks like. This operation has been observed answering a rejected password the same way it '
    .'answers an unknown payment, so treat this as the expected result rather than as proof the credentials are '
    .'valid. Response code 20 is what proves they are not.';

const REJECTION_ADVICE = '. Check client_id, username and password against the set the bank issued for this '
    .'environment — they differ between test and production.';

beforeEach(function (): void {
    vposConfig();
});

/**
 * Runs `vpos:check` with $client answering the single exchange it makes.
 *
 * Artisan::call rather than the artisan() test helper, because the whole
 * output is needed as a string: two of the assertions below are about what is
 * *not* in it, and a fluent expectation that can only look for what is there
 * cannot make them.
 *
 * @return array{exit: int, output: string}
 */
function runCheck(?ClientInterface $client = null): array
{
    if ($client instanceof ClientInterface) {
        app()->instance(ClientInterface::class, $client);
    }

    $exit = Artisan::call('vpos:check');

    return ['exit' => $exit, 'output' => Artisan::output()];
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
 * The lines every run prints before it sends anything.
 */
function expectProbeHeader(string $output): void
{
    expect($output)
        ->toContain('Environment: test')
        ->toContain('Base URL: https://servicestest.ameriabank.am/VPOS/')
        ->toContain('ClientID: CLIE...')
        ->toContain('Username: USER...')
        ->toContain('Password: (set)')
        ->toContain('BackURL: https://shop.example.test/vpos/back')
        ->toContain('Sending one GetPaymentDetails request for probe PaymentID 00000000-0000-0000-0000-000000000000.');

    expectNoCredentialLeak($output);
}

it('reports rejected credentials when the gateway answers response code 20 as an integer', function (): void {
    $result = runCheck(StubHttpClient::answering(
        200,
        '{"ResponseCode":20,"ResponseMessage":"Wrong client credentials"}',
    ));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: Wrong client credentials'.REJECTION_ADVICE)
        ->and($result['output'])->not->toContain(AMBIGUITY_NOTE);
});

it('reports rejected credentials when the gateway answers response code 20 as a string', function (): void {
    $result = runCheck(StubHttpClient::answering(
        200,
        '{"ResponseCode":"20","ResponseMessage":"Wrong client credentials"}',
    ));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: Wrong client credentials'.REJECTION_ADVICE)
        ->and($result['output'])->not->toContain(AMBIGUITY_NOTE);
});

it('says a rejection carried no message when the gateway sent none', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":20}'));

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('The gateway rejected these credentials with response code 20: (no message)'.REJECTION_ADVICE);

    expectNoCredentialLeak($result['output']);
});

it('treats a gateway fault as the expected answer, and says what it does not prove', function (): void {
    $result = runCheck(StubHttpClient::answering(500, '{"Message":"An error has occurred."}'));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('GetPaymentDetails returned a gateway fault (HTTP 500) carrying no response code, so the '
            .'request was not answered; do not retry it. The gateway reported: An error has occurred.')
        ->toContain(AMBIGUITY_NOTE);
});

it('reports any other response code as the gateway worded it, with the same caveat', function (): void {
    $result = runCheck(StubHttpClient::answering(
        200,
        '{"ResponseCode":"550","ResponseMessage":"System Error"}',
    ));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('GetPaymentDetails answered with response code 550: System Error')
        ->toContain(AMBIGUITY_NOTE);
});

it('says an answer carried no message when the gateway sent none', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":"550","Description":"System Error"}'));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('GetPaymentDetails answered with response code 550: (no message)');

    expectNoCredentialLeak($result['output']);
});

it('reports that nothing was learned when the gateway could not be reached', function (): void {
    $result = runCheck(StubHttpClient::failingWith(new StubClientException('the socket timed out')));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])
        ->toContain('Could not reach the gateway, so nothing was learned about the credentials. '
            .'The GetPaymentDetails request could not be completed: '.StubClientException::class)
        ->and($result['output'])->not->toContain(AMBIGUITY_NOTE);
});

it('reports a success code as the one answer that settles the question', function (): void {
    $result = runCheck(StubHttpClient::answering(200, '{"ResponseCode":"00"}'));

    expectProbeHeader($result['output']);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('The gateway answered GetPaymentDetails with a success code for a PaymentID that should '
            .'not exist. Nothing but an authenticated caller gets a success code, so the credentials are good; '
            .'treat the answer itself as suspect and check which environment this ran against.')
        ->and($result['output'])->not->toContain(AMBIGUITY_NOTE);
});

it('refuses an unknown environment before it sends anything', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('The Ameriabank vPOS configuration is not usable. Unknown Ameriabank vPOS environment '
            .'"staging". Set ameriabank-vpos.environment (AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.')
        ->and($result['output'])->not->toContain('Environment: ');

    expectNoCredentialLeak($result['output']);
});

it('refuses a blank credential before it sends anything, and names the field', function (): void {
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

    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');
    $result = runCheck($stub);

    expect($result['exit'])->toBe(1)
        ->and($stub->requests())->toBe([])
        ->and($result['output'])
        ->toContain('ClientID: (not set)')
        ->toContain('Username: (not set)')
        ->toContain('Password: (not set)')
        ->toContain('The Ameriabank vPOS configuration is not usable. Credential field "ClientID" must not be blank.');
});

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

    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');
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

    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');
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

it('sends its probe to the operation and environment it announced', function (): void {
    $stub = StubHttpClient::answering(200, '{"ResponseCode":"00"}');

    runCheck($stub);

    $requests = $stub->requests();

    expect($requests)->toHaveCount(1)
        ->and($requests[0]->getMethod())->toBe('POST')
        ->and($requests[0]->getUri()->__toString())
        ->toBe('https://servicestest.ameriabank.am/VPOS/api/VPOS/GetPaymentDetails')
        ->and($requests[0]->getBody()->__toString())
        ->toContain('"PaymentID":"00000000-0000-0000-0000-000000000000"');
});
