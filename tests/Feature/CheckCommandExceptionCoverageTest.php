<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\DeclinedException;
use DavitVardanyan\AmeriabankVpos\Exception\DuplicateOrderException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException as BridgeConfigurationException;
use Illuminate\Support\Facades\Artisan;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/*
 * No exception the client package can raise may escape CheckCommand::handle().
 *
 * ## Why this guard exists at all
 *
 * An uncaught exception leaves an artisan command on exit 1, and in this
 * command's contract exit 1 does not mean "something went wrong" — it means
 * **the gateway proved these credentials wrong**. So a client exception nobody
 * anticipated does not degrade to a vague failure; it publishes a specific,
 * confident and unfounded claim about the merchant's configuration, in the
 * direction that sends them hunting for a credential problem they do not have.
 * That is the same defect class this command was rewritten to remove, wearing a
 * different hat.
 *
 * Reading the catch list once and finding it complete settles today and nothing
 * else. The client is a separate package on its own release cycle: a new
 * exception type added there arrives through `composer update`, changes nothing
 * in this repository, and is caught by no clause here. This guard is what makes
 * that arrival loud.
 *
 * ## How "is handled" is established, and why this mechanism rather than another
 *
 * By **driving each exception type through the real command** — not by
 * reflecting over the catch clauses in CheckCommand's source.
 *
 * A source-reading guard can tell you a `catch` clause names a type. It cannot
 * tell you the clause is reachable, that it is ordered before a wider clause
 * that swallows it first, that the handler it delegates to returns an exit code
 * rather than rethrowing, or that the code it returns is the one the outcome
 * table calls for. Every one of those is a way for a listed type to be handled
 * on paper and mishandled in fact, and each of them fails in the quiet
 * direction. Driving the exception through `Artisan::call()` and reading the
 * integer that comes back tests the property the contract is actually about.
 *
 * The injection point is the PSR-18 client. `HttpTransport::dispatch()` calls
 * `sendRequest()` inside a try block whose clauses name only
 * `NetworkExceptionInterface`, `RequestExceptionInterface` and
 * `ClientExceptionInterface`; there is no `catch (Throwable)` anywhere on the
 * path from `PaymentsClient::paymentIdForOrder()` down to that call. A client
 * exception thrown from the stub therefore travels the exact route a
 * client exception raised by the transport itself would travel, and arrives in
 * `handle()`'s try block indistinguishable from the real thing.
 *
 * That is what buys the coverage of the types no gateway answer can produce.
 * `IndeterminateStateException` is **structurally unreachable on this probe** —
 * the transport raises it only for a request that may not be repeated, and
 * `GetPaymentIdRequest::isIdempotent()` is true while `GetPaymentId` is absent
 * from the transport's never-retry list, so a network failure here becomes a
 * `TransportException` instead. `DeclinedException` and `DuplicateOrderException`
 * are unreachable for a different reason: `ResponseCode::toException()`
 * deliberately constructs neither, and says so at length. Injection is the only
 * way to observe what this command does with any of the three, and what it does
 * with them is exactly what a future upstream change would make matter.
 *
 * Note what the guard therefore does *not* claim: it does not claim that these
 * scenarios are how each exception arises in production. That is the other test
 * file's job — `CheckCommandTest` provokes the reachable ones through real
 * gateway answers. This one asks a narrower question of a wider set: given that
 * this type reaches `handle()`, is it classified?
 *
 * ## The subject list is derived, never written down
 *
 * The classes come from the client package's own `src/Exception` directory,
 * located through `ReflectionClass::getFileName()` on the marker interface
 * rather than from a hard-coded vendor path, and filtered to the concrete
 * implementations of `VposExceptionInterface`. Nothing here lists them.
 *
 * What *is* written down is one named factory per class, and that is the point
 * of leverage: a class discovered on disk with no entry in the table below
 * fails this guard by name, and the message tells the contributor that the
 * decision they owe is what `vpos:check` should exit with when the new type
 * arrives. A hand-maintained list would have gone quietly out of date; a
 * hand-maintained *table keyed by a derived list* cannot.
 *
 * Exceptions are built through the client's own named factories, never with
 * `new`, so the messages are the client's own and no message literal is
 * invented here.
 */

/**
 * Every concrete exception the client package can put in front of a caller.
 *
 * Derived at test time: the directory is resolved from the marker interface's
 * own file, every `.php` in it is turned into a class name, and anything that
 * does not autoload is a refusal rather than a skip — a guard that silently
 * ignores what it cannot resolve is a guard that reports success for the wrong
 * reason.
 *
 * @return array<string, array{class-string}>
 */
function coreVposExceptionClasses(): array
{
    $markerFile = (new ReflectionClass(VposExceptionInterface::class))->getFileName();

    if ($markerFile === false) {
        throw new RuntimeException(sprintf('%s has no file on disk to walk.', VposExceptionInterface::class));
    }

    $directory = dirname($markerFile);
    $namespace = (new ReflectionClass(VposExceptionInterface::class))->getNamespaceName();
    $entries = scandir($directory);

    if ($entries === false) {
        throw new RuntimeException(sprintf('Unable to list %s.', $directory));
    }

    $subjects = [];

    foreach ($entries as $entry) {
        if (! str_ends_with($entry, '.php')) {
            continue;
        }

        $className = $namespace.'\\'.substr($entry, 0, -4);

        if (! class_exists($className) && ! interface_exists($className)) {
            throw new RuntimeException(sprintf(
                '%s does not autoload; the client package\'s Exception directory and its autoload map disagree.',
                $className,
            ));
        }

        $reflection = new ReflectionClass($className);

        if (! $reflection->implementsInterface(VposExceptionInterface::class) || ! $reflection->isInstantiable()) {
            continue;
        }

        $subjects[$reflection->getShortName()] = [$className];
    }

    if ($subjects === []) {
        throw new RuntimeException(sprintf(
            'No concrete %s implementation was found in %s, so this guard would have passed without testing anything.',
            VposExceptionInterface::class,
            $directory,
        ));
    }

    ksort($subjects);

    return $subjects;
}

/**
 * What `vpos:check` owes each client exception, and how to raise one.
 *
 * Keyed by class name so the derived subject list decides which rows are
 * required; a row nothing discovers is dead, and a discovery with no row is a
 * failure. `exit` is the outcome table's answer for that type, and `says` is
 * the branch that must have produced it — without it, an exception silently
 * swallowed somewhere upstream would leave the command on the blind-probe's
 * own exit 2 and look identical to a correctly classified one.
 *
 * @return array<class-string, array{factory: Closure(): Throwable, exit: int, says: string}>
 */
function checkCommandExceptionExpectations(): array
{
    return [
        /*
         * Integer 20 — the only code the client classifies as a credential
         * rejection, and one of the two answers this command may report as
         * proof of anything.
         */
        AuthenticationException::class => [
            'factory' => static fn (): Throwable => AuthenticationException::fromResponse(
                'GetPaymentId',
                20,
                'Incorrect Username and Password',
            ),
            'exit' => 1,
            'says' => 'The gateway rejected these credentials with response code 20: Incorrect Username and Password',
        ],

        /*
         * Any business failure code that is not the rejection. It establishes
         * nothing, and the command reports the gateway's own wording for it.
         */
        ApiException::class => [
            'factory' => static fn (): Throwable => ApiException::fromResponse('GetPaymentId', '07', 'Refused'),
            'exit' => 2,
            'says' => 'GetPaymentId answered with response code 07: Refused',
        ],

        /*
         * Never constructed by the client today: ResponseCode::toException()
         * maps no code to it, because no decline has ever been observed and
         * "07" has been seen carrying two unrelated meanings. It stays in the
         * hierarchy as a catchable type, so it stays a subject here.
         */
        DeclinedException::class => [
            'factory' => static fn (): Throwable => DeclinedException::fromResponse('GetPaymentId', '07', 'Declined'),
            'exit' => 2,
            'says' => 'GetPaymentId answered with response code 07: Declined',
        ],

        /*
         * Also never constructed: the gateway answered a re-registered OrderID
         * with a success code rather than the documented duplicate code, so the
         * client declines to map one.
         */
        DuplicateOrderException::class => [
            'factory' => static fn (): Throwable => DuplicateOrderException::fromResponse('GetPaymentId', '01', 'Duplicate'),
            'exit' => 2,
            'says' => 'GetPaymentId answered with response code 01: Duplicate',
        ],

        /*
         * The fault envelope. Inconclusive, because a wrong password has been
         * observed producing the same one as correct credentials.
         */
        GatewayFaultException::class => [
            'factory' => static fn (): Throwable => GatewayFaultException::fromFaultEnvelope(
                'GetPaymentId',
                500,
                'An error has occurred.',
            ),
            'exit' => 2,
            'says' => 'Inconclusive. The gateway answered with a fault envelope rather than a response code',
        ],

        /*
         * Structurally unreachable on this probe, and deliberately sharing a
         * clause with SerializationException rather than getting a branch of
         * its own that no test could ever cover.
         */
        IndeterminateStateException::class => [
            'factory' => static fn (): Throwable => IndeterminateStateException::afterTransportFailure(
                'GetPaymentId',
                null,
                new RuntimeException('the guard\'s stand-in for a transport failure'),
            ),
            'exit' => 2,
            'says' => 'Inconclusive. The exchange did not produce a reply this command could read',
        ],

        /*
         * A reply this client cannot parse. Nothing of the reply is printed:
         * it is unvalidated remote content.
         */
        SerializationException::class => [
            'factory' => static fn (): Throwable => SerializationException::unexpectedPayload(
                'GetPaymentId',
                'the required ResponseMessage field was absent, null, or not text',
            ),
            'exit' => 2,
            'says' => 'Inconclusive. The exchange did not produce a reply this command could read',
        ],

        /*
         * Nothing arrived, so nothing was learned.
         */
        TransportException::class => [
            'factory' => static fn (): Throwable => TransportException::requestFailed(
                'GetPaymentId',
                RuntimeException::class,
                new RuntimeException('the guard\'s stand-in for a client failure'),
            ),
            'exit' => 2,
            'says' => 'Inconclusive. Could not reach the gateway, so nothing was learned about the credentials.',
        ],

        /*
         * The client's own configuration refusal. A fact about the merchant's
         * configuration, so exit 1 rather than 2.
         */
        ConfigurationException::class => [
            'factory' => static fn (): Throwable => ConfigurationException::blankCredential('ClientID'),
            'exit' => 1,
            'says' => 'Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict '
                .'on the credentials. Credential field "ClientID" must not be blank.',
        ],

        /*
         * The client's own validation refusal, which this command may no
         * longer name a key for.
         *
         * The row is deliberately built from `maxAttemptsOutOfRange()` and
         * from the budget the fixture configures — the one refusal the command
         * used to recognise and name `ameriabank-vpos.max_attempts` for. That
         * branch is gone with the attempt budget moving to the bridge, so this
         * row asserts the *hardest* case of the generic branch: even the
         * refusal that once had a named message of its own now gets the
         * message that claims nothing.
         *
         * It is injected through the PSR-18 seam because configuration can no
         * longer produce it at all. `AmeriabankVposServiceProvider::maxAttempts()`
         * refuses an out-of-range budget before `new Vpos(...)` is entered, so
         * the transport is never constructed with a value it would refuse.
         */
        ValidationException::class => [
            'factory' => static fn (): Throwable => ValidationException::maxAttemptsOutOfRange(
                configuredAttemptBudget(),
            ),
            'exit' => 1,
            'says' => sprintf(
                'The Ameriabank vPOS client refused an input, and this run stopped without reaching a verdict '
                .'on the credentials. This command cannot tell what was refused, which setting the refused '
                .'input came from, or whether it came from a setting at all — the client\'s own words are the '
                .'whole of what is known here. %s',
                ValidationException::maxAttemptsOutOfRange(configuredAttemptBudget())->getMessage(),
            ),
        ],
    ];
}

/**
 * The attempt budget the fixture configured, read back rather than restated.
 *
 * The refusals injected below are built from it so that the case being made is
 * the strongest one available: this is the value the provider would really have
 * handed the client, and the refusal of it is the one `vpos:check` used to
 * recognise and name a configuration key for. Writing the number down here
 * instead would leave the guard asserting against a value the fixture may no
 * longer configure.
 *
 * A non-integer here is a refusal rather than a silent 0. The provider now
 * refuses a non-integer budget outright, so a fixture holding one would fail
 * before the seam these guards inject through was ever reached, and every test
 * in this file would be reporting on a run that never happened.
 */
function configuredAttemptBudget(): int
{
    $configured = config('ameriabank-vpos.max_attempts');

    if (! is_int($configured)) {
        throw new RuntimeException(
            'The fixture configured no integer ameriabank-vpos.max_attempts, so this guard cannot rebuild the '
            .'refusal the client would have raised and would be asserting against a number it invented.',
        );
    }

    return $configured;
}

/**
 * The sentence `vpos:check` opens with when it has established that this
 * package is set up wrongly — captured from a run that establishes it, never
 * transcribed.
 *
 * It exists because the two guards below assert that sentence **absent**, and
 * an absence assertion is only worth what its needle is worth. A quoted copy
 * stops matching the day the sentence is reworded, and a needle that matches
 * nothing passes against every output there is — including the one that
 * carries the claim. That is not hypothetical here: both guards asserted the
 * previous wording, *"The Ameriabank vPOS configuration is not usable"*, and
 * went on passing for the wrong reason from the moment step 7a reworded it.
 * Nothing in a green run says so, because mutation cannot cover a negative.
 *
 * So the needle is observed rather than written: a run really is misconfigured
 * — an environment the client does not know — the refusal that run prints is
 * rebuilt from its own factory, and the opening is whatever this command put in
 * front of it on that line. A wording change moves the needle with it, and a
 * branch that stops printing an opening at all is a refusal here rather than a
 * silent pass.
 *
 * The provocation is a configuration mistake and not an injected throwable on
 * purpose: `misconfigured()` is the branch the generic refusal must not be
 * confused with, and this is the branch a merchant reaches it by.
 */
function misconfiguredOpening(): string
{
    $refusal = BridgeConfigurationException::unknownEnvironment('staging')->getMessage();

    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    Artisan::call('vpos:check');

    $output = Artisan::output();
    $position = strpos($output, $refusal);

    if ($position === false) {
        throw new RuntimeException(
            'A run configured with an environment the client does not know did not print the refusal it was '
            .'provoked with, so the opening in front of it could not be observed. Every needle taken from here '
            .'would have been invented instead.',
        );
    }

    $before = substr($output, 0, $position);
    $lineStart = strrpos($before, "\n");
    $opening = trim($lineStart === false ? $before : substr($before, $lineStart + 1));

    if ($opening === '') {
        throw new RuntimeException(
            'The configuration branch printed its refusal with no opening of its own, so there is no claim for '
            .'the generic branch to be asserted free of. An absence assertion over an empty needle passes '
            .'against every output there is, which is the direction that hides the defect.',
        );
    }

    return $opening;
}

/**
 * A PSR-18 client that raises $failure where the transport would have.
 *
 * Anonymous rather than a named double in tests/Support, because nothing else
 * needs it and because the whole of what it does is visible from here. It
 * throws before recording anything: this guard asks what the command does with
 * a failure, not what it sent.
 */
function clientRaising(Throwable $failure): ClientInterface
{
    return new readonly class($failure) implements ClientInterface
    {
        public function __construct(private Throwable $failure) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw $this->failure;
        }
    };
}

/**
 * Every named factory the client's ValidationException offers, derived.
 *
 * Public static methods declared on the class itself, which is what the client's
 * own convention makes a factory: exceptions are never constructed with `new` by
 * a caller, so the static surface is the whole of what can ever be raised.
 *
 * @return array<string, array{string}>
 */
function coreValidationExceptionFactories(): array
{
    $subjects = [];

    foreach ((new ReflectionClass(ValidationException::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isStatic() || $method->getDeclaringClass()->getName() !== ValidationException::class) {
            continue;
        }

        $subjects[$method->getName()] = [$method->getName()];
    }

    if ($subjects === []) {
        throw new RuntimeException(sprintf(
            'No named factory was found on %s, so this guard would have passed without inspecting anything.',
            ValidationException::class,
        ));
    }

    ksort($subjects);

    return $subjects;
}

/**
 * Which of those factories a `vpos:check` run can actually provoke.
 *
 * **Every row now reads false, and that is the change task 005 made.**
 * `maxAttemptsOutOfRange()` was the one true row: the transport range-checks
 * the attempt budget it is constructed with, and that budget came from
 * `ameriabank-vpos.max_attempts`. `AmeriabankVposServiceProvider::maxAttempts()`
 * now refuses a budget outside 1..5 at the `maxAttempts:` argument position of
 * `new Vpos(...)`, which PHP evaluates before entering the constructor — so no
 * transport is ever built with a value it would refuse, and the client's own
 * refusal of that value cannot be raised by any configuration.
 *
 * `CheckCommand` therefore names a configuration key for **no** validation
 * refusal at all. Every one that reaches it is, by construction, a refusal this
 * package did not anticipate, and it gets the message that claims nothing about
 * what was refused or where it came from.
 *
 * **What the table is for now.** It is the recorded reading itself. A factory
 * that really does become reachable deserves a named message of its own, and
 * recording the reading against a list derived from the class is what makes
 * that arrival visible instead of silent.
 *
 * **What it does and does not catch.** A factory added upstream fails the guard
 * by name, because the derived list grows and this table does not. A factory
 * already here that gains a *new call site* on this exchange is invisible to it
 * — reachability is not something reflection can answer. The concrete instance
 * of that risk is guarded behaviourally instead, in `CheckCommandTest`: the
 * sentinel OrderID is asserted to reach the wire, so a range check appearing on
 * OrderID goes red there rather than being absorbed into a message about a
 * setting.
 *
 * @return array<string, array{reaches: bool, why: string}>
 */
function validationFactoryReachability(): array
{
    return [
        'amountNotPositive' => [
            'reaches' => false,
            'why' => 'Amount is constructed for a payment; GetPaymentId carries no amount.',
        ],
        'blankValue' => [
            'reaches' => false,
            'why' => 'The only call site on this exchange is the endpoint builder, which is handed the request '
                .'model\'s own operation constant and so can never be blank. The others are payment and '
                .'callback fields this command never supplies.',
        ],
        'callbackOrderMismatch' => [
            'reaches' => false,
            'why' => 'Raised while confirming a callback, which this command never does.',
        ],
        'callbackOrderUnconfirmable' => [
            'reaches' => false,
            'why' => 'Raised while confirming a callback, which this command never does.',
        ],
        'credentialFieldInRequestBody' => [
            'reaches' => false,
            'why' => 'Raised for a request DTO that builds a credential into its own body. This command builds no '
                .'request, and GetPaymentId carries only an OrderID.',
        ],
        'malformedValue' => [
            'reaches' => false,
            'why' => 'Raised by Amount and by callback parsing, neither of which this exchange touches.',
        ],
        'maxAttemptsOutOfRange' => [
            'reaches' => false,
            'why' => 'The transport range-checks the attempt budget it is constructed with, and that budget is '
                .'ameriabank-vpos.max_attempts and nothing else — which AmeriabankVposServiceProvider::maxAttempts() '
                .'now refuses before new Vpos(...) is entered, so no transport is ever constructed with a value '
                .'the transport would refuse.',
        ],
        'timeoutOutOfRange' => [
            'reaches' => false,
            'why' => 'Raised by InitPaymentRequest, and this command may not register an order.',
        ],
        'unsupportedPaymentType' => [
            'reaches' => false,
            'why' => 'Raised by the binding requests, which this command never sends.',
        ],
    ];
}

dataset('core vpos exceptions', fn (): array => coreVposExceptionClasses());

dataset('core validation factories', fn (): array => coreValidationExceptionFactories());

it('classifies every client exception that can reach it, and lets none escape', function (string $className): void {
    vposConfig();

    $expectations = checkCommandExceptionExpectations();

    expect(array_key_exists($className, $expectations))->toBeTrue(sprintf(
        '%s is a concrete %s that this guard has no expectation for. It was found on disk in the client package\'s '
        .'Exception directory, which means it can reach CheckCommand::handle() and this repository has never decided '
        .'what should happen when it does. An unhandled exception leaves an artisan command on exit 1, and exit 1 in '
        .'this command means "the gateway proved these credentials wrong" — so doing nothing publishes a confident, '
        .'unfounded claim about the merchant\'s configuration. Decide the outcome, catch the type in '
        .'CheckCommand::handle(), and add its row here. Anything that proves nothing about credentials is exit 2.',
        $className,
        VposExceptionInterface::class,
    ));

    $expected = $expectations[$className];

    app()->instance(ClientInterface::class, clientRaising(($expected['factory'])()));

    try {
        $exit = Artisan::call('vpos:check');
    } catch (Throwable $escaped) {
        throw new RuntimeException(sprintf(
            '%s escaped CheckCommand::handle() instead of being classified. Laravel would have exited 1, and exit 1 '
            .'in this command means the credentials were proven rejected — a claim nothing here supports. Catch it '
            .'and classify it; anything that proves nothing about credentials is exit 2.',
            $escaped::class,
        ), 0, $escaped);
    }

    $output = Artisan::output();

    expect($output)->toContain($expected['says']);

    expect($exit)->toBe($expected['exit'], sprintf(
        '%s must leave vpos:check on exit %d, and it left it on %d.',
        $className,
        $expected['exit'],
        $exit,
    ));

    expect($exit)->not->toBe(0, sprintf(
        '%s left vpos:check on exit 0, which claims the credentials were proven valid. No exception proves that.',
        $className,
    ));
})->with('core vpos exceptions');

/*
 * The clause of last resort, reached deliberately.
 *
 * The guard above walks the client package's own exception directory, so it can
 * only ever ask about types that package declares. `handle()` ends in a
 * `catch (Throwable)` for everything else, and everything else is not
 * hypothetical: a merchant's own PSR-18 client may raise anything at all, and
 * `HttpTransport::dispatch()` wraps only the three PSR-18 interfaces, so a plain
 * RuntimeException travels the same route the injected client exceptions travel
 * and arrives unwrapped. A `back_url` naming a route with required parameters
 * used to reach it from the other direction, through the framework's own
 * UrlGenerationException; BackUrlResolver now converts that into a named
 * configuration failure, which is why this clause is exercised through the
 * PSR-18 seam alone. Closing one route into a clause of last resort is not a
 * reason to remove it — it is what the clause is for.
 *
 * Two properties are asserted, and the second is the one that is easy to lose.
 *
 * The exit code must be 2. Without the clause the exception leaves the command
 * on Laravel's default handling, which exits **1** — and exit 1 here is the
 * specific claim that the gateway refused these credentials, published for a
 * run in which the gateway may never have been reached.
 *
 * The message must name the class and withhold the throwable's own text. A class
 * name is a fixed string chosen by whoever wrote the class and is what makes the
 * failure searchable. A message is composed at the throw site from whatever was
 * in scope there, and this command cannot know what an arbitrary component put
 * in one — a PSR-18 client that appends the request it was sending would append
 * the merged credential payload with it. The fixture's message is written so
 * that a leak would be unmistakable rather than plausible.
 */
it('classifies a throwable no clause names, without repeating what it said', function (): void {
    vposConfig();

    app()->instance(ClientInterface::class, clientRaising(new RuntimeException(
        'UNCLASSIFIED-FAILURE-TEXT-MUST-NEVER-BE-PRINTED-4f0a1d63',
    )));

    $exit = Artisan::call('vpos:check');
    $output = Artisan::output();

    expect($exit)->toBe(2, 'A throwable this command does not classify establishes nothing about the credentials, '
        .'so it must exit 2. Exit 1 is the claim that the gateway refused them, and it is what Laravel\'s default '
        .'handling would have exited with had the terminal catch clause not caught this.')
        ->and($output)
        ->toContain('Inconclusive. The run failed with an unexpected RuntimeException before it could establish '
            .'anything, so nothing about the credentials was learned — neither that they work nor that they do '
            .'not. The failure\'s own message is not printed, because an unclassified failure can carry whatever '
            .'was in scope where it was raised. The class named above is the component that raised it: check '
            .'what this command printed before this line, and the configuration value that component reads, '
            .'then re-run.')
        ->and($output)->not->toContain('UNCLASSIFIED-FAILURE-TEXT-MUST-NEVER-BE-PRINTED')
        ->and($output)->not->toContain('4f0a1d63');
});

/*
 * The inventory behind the one validation refusal `vpos:check` names. See
 * validationFactoryReachability() for what these two guards do and do not
 * catch.
 */
it('has a recorded reachability verdict for every validation refusal the client can raise', function (string $factory): void {
    $reachability = validationFactoryReachability();

    expect(array_key_exists($factory, $reachability))->toBeTrue(sprintf(
        'ValidationException::%s() is a factory this repository has never assessed. vpos:check names a '
        .'configuration key for none of these: the bridge refuses the attempt budget before new Vpos(...) is '
        .'entered, so nothing a merchant configures can raise the client\'s own refusal and every refusal that '
        .'reaches the command takes the generic branch — honest, but saying nothing an operator can act on. So '
        .'%s() arriving unassessed produces a vague message rather than a wrong one. Decide whether it can reach '
        .'a GetPaymentId exchange and record it; if it can, it has earned a named branch of its own, recognised '
        .'from the client\'s factory built with the configured value, never from a message literal.',
        $factory,
        $factory,
    ));
})->with('core validation factories');

/*
 * The table's own verdict, asserted rather than left as prose.
 *
 * It used to read "exactly one factory is reachable, and it is the attempt
 * budget". Task 005 moved that check to the bridge, so the honest reading is
 * now that **none** of them is reachable from a `vpos:check` run, and this is
 * where that is written down as an assertion instead of a comment.
 *
 * Asserted as a whole list rather than as a count, so a row flipped to true
 * names itself in the failure. `toBe()` takes a real failure message, unlike
 * `toContain()`, so the message is where the decision the contributor owes is
 * explained.
 */
it('records no validation refusal as reachable, since the command names a key for none', function (): void {
    $reachable = array_keys(array_filter(
        validationFactoryReachability(),
        static fn (array $verdict): bool => $verdict['reaches'],
    ));

    expect($reachable)->toBe([], sprintf(
        'A ValidationException factory is recorded as reachable on a GetPaymentId exchange (%s), and CheckCommand '
        .'names a configuration key for none of them: every validation refusal now takes the generic branch, '
        .'which is honest but says nothing an operator can act on. Either the reading is wrong — the bridge '
        .'refuses the attempt budget before new Vpos(...) is entered, so nothing it configures can raise the '
        .'client\'s own refusal — or a genuinely reachable factory has arrived and has earned a named branch of '
        .'its own, recognised from the client\'s factory built with the configured value, never from a message '
        .'literal.',
        implode(', ', $reachable),
    ));
});

/*
 * The generic branch: the only thing a validation refusal can mean here.
 *
 * It was the fallback behind a named attempt-budget branch until task 005 moved
 * the attempt budget to the bridge. Task 003's branch survives that move — it
 * was not deleted with the named one, and these two tests are what establish
 * that it is still reachable rather than dead code kept for appearances.
 *
 * Two ways in, and both are asserted because they fail differently. The first
 * is a refusal from another factory entirely — the shape a future upstream
 * range check would take. The second is `maxAttemptsOutOfRange()` itself, the
 * one refusal this command used to recognise by name, arriving to find there is
 * no longer a name to be recognised by.
 *
 * Both are injected through the PSR-18 seam rather than provoked from
 * configuration, because configuration cannot produce either: the provider
 * refuses an out-of-range budget before `new Vpos(...)` is entered, so the
 * transport is never constructed with a value it would refuse and the client's
 * own refusal is unreachable from any setting. That is the acceptance criterion
 * the seam exists to make testable — a branch that can only be reached by
 * injection is a branch nothing a merchant configures can reach.
 *
 * The key and the environment variable are asserted absent whole. Naming them
 * for a refusal that has nothing to do with them is the defect these tests
 * exist for, and it is the first sentence — the one an operator acts on.
 *
 * So is the withdrawn claim. `misconfigured()` opens by saying the package is
 * set up wrongly, and this branch cannot establish that a setting was involved
 * at all — the client refused an input and this command cannot tell where the
 * input came from. That opening is therefore asserted absent, and it is
 * `misconfiguredOpening()` that supplies it: read out of a run that really is
 * misconfigured rather than quoted, so it cannot quietly stop matching. Both
 * assertions here quoted the previous wording and both were passing against a
 * sentence that no longer existed until this was fixed.
 *
 * A third needle was **removed** rather than repointed. *"reached the client
 * as"* belonged to `attemptBudgetRefused()`, the named branch task 005 deleted,
 * and no code anywhere can emit those words again — an absence assertion whose
 * needle exists nowhere is decoration, not a guard. What it was really holding
 * is held by the two needles beside it, which are live: the branch's whole
 * offence would be naming `ameriabank-vpos.max_attempts` or the variable behind
 * it, and both of those strings do still exist in messages this command can
 * print.
 */
it('gives a validation refusal from another factory a generic message that names no configuration key', function (): void {
    $setUpWrongly = misconfiguredOpening();

    vposConfig();

    $refusal = ValidationException::timeoutOutOfRange(1201);

    expect($refusal->getMessage())->not->toBe(
        ValidationException::maxAttemptsOutOfRange(configuredAttemptBudget())->getMessage(),
        'This fixture is only a test of the generic branch while its message differs from the attempt-budget '
        .'refusal this configuration would have produced.',
    );

    app()->instance(ClientInterface::class, clientRaising($refusal));

    $exit = Artisan::call('vpos:check');
    $output = Artisan::output();

    expect($exit)->toBe(1, 'A refusal the operator has an account of and can act on exits 1 whether or not this '
        .'command can say what was refused or where it came from. Exit 2 means "nothing was established, '
        .'re-run", and there is nothing to re-run: the same refusal arrives again until something changes.')
        ->and($output)->toContain('The Ameriabank vPOS client refused an input, and this run stopped without '
            .'reaching a verdict on the credentials. This command cannot tell what was refused, which setting '
            .'the refused input came from, or whether it came from a setting at all — the client\'s own words '
            .'are the whole of what is known here. '.$refusal->getMessage().' Check the ameriabank-vpos '
            .'configuration against that message; if nothing there matches, the refusal is not about a value '
            .'you set, and this output is worth reporting as a defect in this package rather than a mistake in '
            .'your configuration.')
        ->and($output)->not->toContain($setUpWrongly)
        ->and($output)->not->toContain('max_attempts')
        ->and($output)->not->toContain('AMERIABANK_VPOS_MAX_ATTEMPTS');

    /*
     * The generic refusal carries no blind pointer, and this run is blind:
     * Artisan::call() above is given no --order-id.
     *
     * `CheckCommand::pointBlindRunAtOrderId()`'s docblock excludes the
     * configuration refusals, and this seam is the only blind run that enters
     * this branch at all, because nothing a merchant configures can reach it.
     */
    expect($output)->not->toContain(blindPointer());
});

it('gives the attempt-budget refusal itself the generic message, naming no key for it either', function (): void {
    $setUpWrongly = misconfiguredOpening();

    vposConfig();

    $refusal = ValidationException::maxAttemptsOutOfRange(configuredAttemptBudget());

    app()->instance(ClientInterface::class, clientRaising($refusal));

    $exit = Artisan::call('vpos:check');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('The Ameriabank vPOS client refused an input, and this run stopped without '
            .'reaching a verdict on the credentials. This command cannot tell what was refused, which setting '
            .'the refused input came from, or whether it came from a setting at all')
        ->and($output)->toContain($refusal->getMessage())
        ->and($output)->not->toContain($setUpWrongly)
        ->and($output)->not->toContain('max_attempts')
        ->and($output)->not->toContain('AMERIABANK_VPOS_MAX_ATTEMPTS');
});
