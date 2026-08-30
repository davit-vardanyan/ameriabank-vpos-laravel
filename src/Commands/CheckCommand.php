<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Commands;

use const FILTER_VALIDATE_INT;

use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException as ClientConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

use function filter_var;
use function is_int;
use function is_string;
use function sprintf;
use function str_replace;
use function substr;

/**
 * Asks the gateway what it will say about these credentials, and reports only
 * what the answer actually establishes.
 *
 * Configuration can be complete, well-formed and still wrong: the bank issues
 * separate credentials per environment, and a set that is valid on the sandbox
 * is rejected in production. Nothing local can tell the difference. So this
 * command sends one real request and reads the reply.
 *
 * ## Why the probe is GetPaymentId
 *
 * The decisive reason is not how well the two operations discriminate a
 * credential failure. It is that `GetPaymentIdRequest::requiresClientId()`
 * returns **true** while `PaymentDetailsRequest::requiresClientId()` returns
 * **false** — so `GetPaymentDetails` never puts ClientID on the wire at all,
 * and a merchant who has typed their ClientID wrongly is *structurally*
 * undetectable on it, under any conceivable gateway behaviour rather than
 * merely under the behaviours anyone has observed. GetPaymentId is the only
 * operation this command may safely call that can ever see a wrong ClientID.
 *
 * Secondarily it is also the only operation ever observed returning a genuine
 * credential rejection — string `"20"` with the gateway's own "Incorrect
 * Username and Password". On GetPaymentDetails that row is unreachable, because
 * `ResponseCode::isAuthenticationFailure()` compares against the integer 20 and
 * that endpoint answers with strings.
 *
 * ## Two modes, and only one of them can prove anything positive
 *
 * With `--order-id`, the probe asks about an order the merchant registered.
 * That is the one cell where a paired experiment exists: correct credentials
 * answered a success code and returned a PaymentId, and a wrong password
 * answered code 20. A success code there is therefore real evidence.
 *
 * Without it, the probe asks about a sentinel OrderID. The client's
 * CONVENTIONS.md records that one shape is still unseen — what the gateway
 * answers for an OrderID it does not know — and it is unseen under *both*
 * credential states. So a success code in the blind mode is not evidence in
 * either direction, and this command says so and exits 2 rather than reporting
 * a verdict the gateway never gave.
 *
 * The same discipline governs the failures. CONVENTIONS.md §4.25 and §4.26
 * record a wrong password producing the *same* fault envelope, and the same
 * `"550"`, that correct credentials received — both observed on
 * GetPaymentDetails, which is the only endpoint where the question has been
 * put. §4.26 draws the general rule from them all the same, that a caller can
 * infer nothing about its credentials from a fault, and neither reply is proof
 * of anything, so both are inconclusive here.
 *
 * ## What is never printed
 *
 * The password is never read into this class at all — only whether the key
 * holds anything. The ClientID and username are truncated to their first four
 * characters, which is enough to tell two credential sets apart in a screenshot
 * and not enough to use. The PaymentID the gateway returns is never printed in
 * either mode: it belongs to a real order, and this output goes into terminals
 * and CI logs.
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vpos:check {--order-id= : An OrderID this merchant registered. Supplied, a success code proves the credentials valid; omitted, the probe can only detect a rejection.}';

    /**
     * @var string
     */
    protected $description = 'Ask the Ameriabank vPOS gateway what it says about the configured credentials. Makes one real HTTP request.';

    /**
     * The configuration namespace this command reads.
     */
    private const string CONFIG_KEY = 'ameriabank-vpos';

    /**
     * The operation the probe uses, for the lines that announce and report it.
     *
     * Display text mirroring the client's own request model. Nothing branches
     * on it; the failure messages below take the operation from the failure
     * they are reporting.
     */
    private const string PROBE_OPERATION = 'GetPaymentId';

    /**
     * The credential rejection code in its string form.
     *
     * The client classifies only the integer 20 as an authentication failure,
     * deliberately, because the string form is overloaded across endpoints —
     * it is an entitlement refusal on the binding endpoints and a credential
     * rejection on this one. This command asks exactly one endpoint, the one
     * where the string form has been observed carrying "Incorrect Username and
     * Password", so it reads the string form as a rejection too.
     */
    private const string REJECTED_CODE_AS_TEXT = '20';

    private const string TRUNCATED = '...';

    private const string NOT_SET = '(not set)';

    private const string SET = '(set)';

    private const string NO_MESSAGE = '(no message)';

    /**
     * The standing caveat, printed on every run whatever it goes on to find.
     *
     * One unconcatenated literal on purpose: a class constant is not an
     * executable statement, so a concatenated one is mutated by the mutation
     * tool on lines no coverage report can ever contain, and every such mutant
     * is scored uncovered without a test being run.
     */
    private const string NO_RELIABLE_CHECK = 'The Ameriabank vPOS gateway offers no reliable credential check. No operation this package can safely call has been observed answering in a way that proves credentials are valid when the order asked about is not one the merchant registered. InitPayment is the only operation with an unambiguous credential rejection, and it is barred from a diagnostic because it registers a real order.';

    /**
     * Chooses the probe, prints what the run can establish, then sends it.
     *
     * The option is validated before anything else happens, because a
     * mistyped `--order-id` is a usage mistake rather than an answer about the
     * credentials, and reporting it as a configuration failure would attach a
     * verdict to a run that never sent anything.
     *
     * Each configuration value is printed as it is resolved rather than in one
     * block at the end, so a run that dies on an unresolvable `back_url` still
     * shows the environment and base URL it got that far with.
     *
     * The client is resolved inside the try, not injected into this method:
     * building it is where a blank credential and an out-of-range attempt
     * budget are refused, and a refusal that happened during method injection
     * would escape before this command could report it as the configuration
     * problem it is.
     *
     * The success verdict is returned from inside the try as well, so that the
     * environment resolved a few lines above is still in scope for it without
     * a variable that has to be given a placeholder value first — a placeholder
     * assigned before the try and overwritten inside it is unobservable, and an
     * unobservable value is one no test can pin.
     *
     * The last clause catches `Throwable`, and it is ordered last so that it
     * cannot shadow the specific ones. Everything above it names a condition
     * this command understands; that one exists because an exception nobody
     * listed would otherwise leave the command on Laravel's default handling,
     * which prints a stack trace and exits **1** — and exit 1 here is the
     * specific claim that the gateway refused these credentials. A route name
     * in `back_url` that needs parameters reaches it today: the URL generator
     * raises a framework exception that is not an `InvalidArgumentException`,
     * so the resolver does not convert it.
     */
    public function handle(Application $app, Repository $config, BackUrlResolver $backUrl): int
    {
        $sentinelOrderId = $this->sentinelOrderId();
        $requestedOrderId = null;
        $requested = $this->option('order-id');

        if (is_string($requested)) {
            $parsed = filter_var($requested, FILTER_VALIDATE_INT);

            if ($parsed === false) {
                return $this->unusableOrderId($requested);
            }

            $requestedOrderId = $parsed;
        }

        $orderId = $requestedOrderId ?? $sentinelOrderId;

        $this->line($requestedOrderId === null
            ? $this->blindModeNote($sentinelOrderId)
            : $this->orderModeNote($requestedOrderId));

        $this->warn(self::NO_RELIABLE_CHECK);

        try {
            $environment = $this->environment($config);

            $this->detail('Environment', $environment->value);
            $this->detail('Base URL', $environment->restBaseUrl());
            $this->detail('ClientID', $this->masked($config, 'client_id'));
            $this->detail('Username', $this->masked($config, 'username'));
            $this->detail('Password', $this->presence($config, 'password'));
            $this->detail('BackURL', $backUrl->resolve());

            $this->line(sprintf('Sending one %s request for OrderID %d.', self::PROBE_OPERATION, $orderId));

            $app->make(Vpos::class)->payments()->paymentIdForOrder($orderId);

            return $requestedOrderId === null
                ? $this->blindSuccess($sentinelOrderId)
                : $this->proven($requestedOrderId, $environment);
        } catch (AuthenticationException $failure) {
            return $this->rejected($failure->responseCode(), $failure->responseMessage());
        } catch (ApiException $failure) {
            return $this->apiAnswer($failure);
        } catch (GatewayFaultException $failure) {
            return $this->faulted($failure->getMessage());
        } catch (TransportException $failure) {
            return $this->unreachable($failure->getMessage());
        } catch (SerializationException|IndeterminateStateException $failure) {
            return $this->unreadable($failure->getMessage());
        } catch (ClientConfigurationException|ConfigurationException $failure) {
            return $this->misconfigured($failure->getMessage());
        } catch (ValidationException $failure) {
            return $this->attemptBudgetRefused($config, $failure->getMessage());
        } catch (Throwable $failure) {
            return $this->unexpected($failure::class);
        }
    }

    /**
     * The OrderID the blind probe asks about.
     *
     * A named local rather than a class constant because a `const` declaration
     * is not an executable statement, so it appears in no coverage report and
     * the mutation tool scores every mutant on its initialiser as uncovered
     * without running a test. Here the value sits on a covered line, where the
     * suite that pins the announced OrderID kills those mutants.
     *
     * **How this value was chosen.** The client records that `OrderID` carries
     * no range check and that no probe has established one, so nothing about
     * the format rules it in or out; the choice has to rest on what a merchant
     * can own. An order exists only because the merchant registered it with
     * InitPayment, and merchants number orders from a counter or a database
     * key, which is positive and ascending — so a negative OrderID cannot be
     * one a merchant holds. The magnitude is nine digits so that a human
     * reading a gateway-side log sees an obvious sentinel rather than a
     * plausible order, and it stays well inside signed 32-bit range so that a
     * gateway storing OrderID in a narrower integer column cannot wrap it into
     * some *other* value that a merchant might really own. A collision would be
     * harmless — the operation is read-only — but it would make the answer mean
     * something other than what this command reports about it.
     */
    private function sentinelOrderId(): int
    {
        return -999999999;
    }

    /**
     * `--order-id` was given something that is not an integer.
     *
     * Refused rather than coerced. `(int) 'abc'` is 0, so coercion would send a
     * different probe from the one the merchant asked for and then report the
     * result as though it answered their question. Exit 2, because nothing was
     * sent and therefore nothing was established — exit 1 is reserved for a
     * verdict the gateway or the configuration actually supports.
     *
     * The offending value is echoed because it is the merchant's own typo and
     * the message is useless without it. It is an order number, not a
     * credential; no configured value reaches this message. It is escaped
     * first all the same — see below.
     *
     * The standing caveat is printed here too, after the correction, so that
     * it really is on every run rather than on every run that gets as far as
     * choosing a probe. Suppressing it here would withhold it from the one run
     * where the operator is still learning what the command can answer.
     *
     * `INVALID` is Symfony's name for exit code 2, and it is used throughout
     * this class for the inconclusive outcome. The name reads oddly here —
     * Symfony documents it as *invalid input*, which this case happens to be
     * but which the other seven uses are not. It is the framework's constant
     * for the code this command's contract publishes as "nothing was
     * established", and a private constant of this class carrying the same 2
     * would sit in a `const` declaration, which is not an executable statement
     * and so is scored by the mutation tool on lines no coverage report can
     * contain.
     */
    private function unusableOrderId(string $given): int
    {
        $this->error(sprintf(
            '--order-id must be an integer OrderID, and "%s" is not one. Nothing was sent to the gateway. '
            .'Pass the OrderID you gave InitPayment, or omit the option to run the blind probe.',
            $this->escaped($given),
        ));

        $this->warn(self::NO_RELIABLE_CHECK);

        return self::INVALID;
    }

    /**
     * A value from the command line, neutralised for the console formatter.
     *
     * Every line this command prints goes through Symfony's output formatter,
     * which reads `<...>` as styling markup. This is the only value printed
     * that the operator typed rather than the package composing it, so it is
     * the only one that can carry markup, and a value that silently restyles
     * the message around it is a value being interpreted rather than reported.
     *
     * Escaping every `<` is marginally broader than the formatter's own
     * escaper, which leaves an already-escaped one alone; the difference shows
     * only as a literal backslash in output that was already going to be
     * printed verbatim. The escaper itself is not reachable through any type
     * this package's manifest names, and reaching past the manifest for one
     * `str_replace` would be the larger fault.
     */
    private function escaped(string $value): string
    {
        return str_replace('<', '\\<', $value);
    }

    /**
     * What the `--order-id` mode can settle, said before it tries.
     */
    private function orderModeNote(int $orderId): string
    {
        return sprintf(
            'Mode: --order-id. The probe asks about OrderID %d, which you have told this command you registered. '
            .'This mode can settle the question in both directions: a success code proves the credentials '
            .'authenticated against an order this merchant owns, and response code 20 proves they were refused.',
            $orderId,
        );
    }

    /**
     * What the blind mode can settle, which is a rejection and nothing else.
     *
     * It says "no --order-id value" rather than "no --order-id" because the
     * option takes an optional value, so `--order-id` written bare arrives
     * indistinguishable from the option being absent. Both land here, and the
     * wording is true of both.
     *
     * It does not say that code 20 *proves* a rejection, which is what this
     * sentence said before, because in this mode it does not. The observed
     * rejection — the gateway's own "Incorrect Username and Password" — was
     * answered for an OrderID the merchant owned. What the gateway answers for
     * an OrderID it does not know is unobserved under both credential states,
     * and that is the argument this command makes for refusing to read a
     * success code here; it applies to a 20 in the same cell with the same
     * force. Believing the rejection is an inference, and it is the safe
     * direction: this is a diagnostic, the gateway's own message is printed
     * beside the code for the operator to judge, and the exit code is
     * unchanged.
     */
    private function blindModeNote(int $sentinelOrderId): string
    {
        return sprintf(
            'Mode: blind. No --order-id value was given, so the probe asks about sentinel OrderID %d, which no merchant '
            .'can have registered. This mode can only detect a rejection: response code 20 is read as a refusal, and '
            .'the gateway\'s own message is printed with it, though only a known OrderID has ever been observed being '
            .'answered 20. No other answer proves anything either way, because what the gateway replies for an '
            .'OrderID it does not know has never been observed under correct or under incorrect credentials. Re-run '
            .'with --order-id set to an order you registered for an answer that can prove the credentials valid.',
            $sentinelOrderId,
        );
    }

    /**
     * A business failure code, which is either the rejection or is not evidence.
     *
     * The rejection test is delegated to the client's own value object so that
     * a future widening of what it classifies is inherited here rather than
     * missed, and the string form it declines to classify is added on top —
     * that gap is documented in the client, and the safe direction for a
     * credential check is to believe a rejection.
     *
     * Everything else lands on the inconclusive line, `"550"` included: the
     * client records that code arriving from both a wrong password and correct
     * credentials, so it distinguishes nothing and gets no branch of its own.
     */
    private function apiAnswer(ApiException $failure): int
    {
        $code = $failure->responseCode();

        if ($this->isCredentialRejection($code)) {
            return $this->rejected($code, $failure->responseMessage());
        }

        $this->warn(sprintf(
            'Inconclusive. %s answered with response code %s: %s. That is neither a success code nor the rejection '
            .'code 20, so it establishes nothing about the credentials. Quote that code and the gateway\'s message '
            .'to Ameriabank; this package deliberately ships no code table, because the codes it has seen are '
            .'overloaded and a local table would have had to guess which meaning applied.',
            $failure->operation(),
            $code,
            $this->orPlaceholder($failure->responseMessage()),
        ));

        return self::INVALID;
    }

    /**
     * Whether this code says the credentials were refused.
     */
    private function isCredentialRejection(int|string $code): bool
    {
        return ResponseCode::fromWire($code)->isAuthenticationFailure()
            || $code === self::REJECTED_CODE_AS_TEXT;
    }

    /**
     * The gateway answered the fault envelope instead of a response code.
     *
     * Inconclusive, and this is the row the previous version of this command
     * got wrong. A fault was read as proof the credentials had been accepted,
     * on the reasoning that the exchange had got far enough for the gateway to
     * have a view. CONVENTIONS.md §4.26 falsifies that directly: a wrong
     * password produced the same fault as correct credentials, because on that
     * endpoint the fault reaches the response ahead of any credential verdict.
     *
     * The message keeps the two claims apart, because the observation and the
     * rule have different reaches. §4.26's general rule — *"a caller can infer
     * nothing about its credentials from a `GatewayFaultException`"* — is what
     * carries this row to exit 2, and it is stated without qualification. The
     * paired observation behind it was made on GetPaymentDetails, on cases
     * L5.4 and L6.3, and §4.26 scopes its own conclusion to that endpoint in
     * those words: *"On **this endpoint** the fault therefore reaches the
     * response ahead of any credential verdict."* No fault has ever been
     * observed from GetPaymentId at all. Saying so plainly costs a clause and
     * keeps this command's output the kind of source it is read as.
     */
    private function faulted(string $message): int
    {
        $this->warn(sprintf(
            'Inconclusive. The gateway answered with a fault envelope rather than a response code, so it never '
            .'reached a credential verdict this command could read. A fault is not a credential verdict either '
            .'way: on the one endpoint where that has been studied, a wrong password produced the same fault as '
            .'correct credentials, and no fault from %s has ever been observed. %s Re-run; if it persists, report '
            .'the operation, the time and the environment to Ameriabank — nothing here points at your '
            .'configuration.',
            self::PROBE_OPERATION,
            $message,
        ));

        return self::INVALID;
    }

    /**
     * The gateway answered the probe with a success code, in the blind mode.
     *
     * Exit 2, and the reason is spelled out rather than hinted at. This is the
     * single row that the whole redesign of this command exists to get right:
     * the reply to an unknown OrderID is unobserved under both credential
     * states, so it cannot be read as a pass without inventing the evidence.
     */
    private function blindSuccess(int $sentinelOrderId): int
    {
        $this->warn(sprintf(
            'Inconclusive. %s answered with a success code for sentinel OrderID %d, and that establishes nothing '
            .'about the credentials: the reply to an OrderID the gateway does not know has never been observed '
            .'under correct or under incorrect credentials, so it is not evidence in either direction. Re-run with '
            .'--order-id set to an order you registered.',
            self::PROBE_OPERATION,
            $sentinelOrderId,
        ));

        return self::INVALID;
    }

    /**
     * The gateway answered a success code for an order the merchant registered.
     *
     * The one reply this command may report as proof, and only in this mode:
     * the paired experiment exists here — correct credentials answered a
     * success code and a PaymentId for a known order, a wrong password answered
     * code 20 for the same one.
     *
     * The returned PaymentID is deliberately absent from the message and is
     * never read out of the response at all.
     *
     * **The premise is restated in the verdict, not only before the send.**
     * The whole of this row rests on the OrderID being one the merchant
     * registered under this ClientID, and that is a claim this command takes on
     * trust — it has no way to check it. An operator with no order number to
     * hand who passes `--order-id=1` is in the blind cell every other row here
     * refuses to conclude from, and the verdict line is the one line that gets
     * screenshotted, so it carries the condition rather than leaving it four
     * lines up the scrollback.
     *
     * **Production gets a second line.** CONVENTIONS.md §13 records that
     * neither production host has ever returned a byte: the two hosts this
     * verdict's evidence comes from are the sandbox pair. A run against
     * production is the same command against different hosts with zero
     * observations behind it, and printing "Environment: production" three
     * lines above an unqualified verdict leaves the operator to make that
     * connection themselves.
     */
    private function proven(int $orderId, Environment $environment): int
    {
        $this->info(sprintf(
            'The credentials are valid. %s answered with a success code for OrderID %d, so the gateway '
            .'authenticated this ClientID, username and password and looked that order up under them. The '
            .'PaymentID it returned is deliberately not printed. This holds only if OrderID %d is an order you '
            .'registered under this ClientID — nothing here can check that, and if it is not, the reply came from '
            .'the one cell nothing has ever been observed in and means nothing. Re-run with an order you own.',
            self::PROBE_OPERATION,
            $orderId,
            $orderId,
        ));

        if ($environment === Environment::Production) {
            $this->warn(
                'No probe has ever reached a production host. Every observation this verdict rests on was made '
                .'against the sandbox, so a production result has no observational backing: treat it as an '
                .'indication, and let a real payment be the confirmation.',
            );
        }

        return self::SUCCESS;
    }

    /**
     * The gateway refused these credentials.
     */
    private function rejected(int|string $code, string $message): int
    {
        $this->error(sprintf(
            'The gateway rejected these credentials with response code %s: %s. Check client_id, username and '
            .'password against the set the bank issued for this environment — they differ between test and '
            .'production.',
            $code,
            $this->orPlaceholder($message),
        ));

        return self::FAILURE;
    }

    /**
     * Nothing was learned about the credentials, because nothing arrived.
     */
    private function unreachable(string $message): int
    {
        $this->warn(sprintf(
            'Inconclusive. Could not reach the gateway, so nothing was learned about the credentials. %s Check '
            .'network egress from this host to the base URL printed above — a proxy, an egress allowlist and DNS '
            .'are the usual three — and re-run.',
            $message,
        ));

        return self::INVALID;
    }

    /**
     * The exchange happened but produced nothing this command can read.
     *
     * Two conditions land here, and neither says anything about credentials:
     *
     * - the reply could not be decoded, or its shape was not one the client
     *   understands — the gateway answered, but not in a language this package
     *   can turn into a verdict;
     * - a non-idempotent operation failed in transport, so whether it arrived
     *   at all is unknown.
     *
     * **Both must be caught even though only the first can arrive today.** An
     * uncaught exception leaves this command on exit 1, and exit 1 here means
     * *the credentials were proven rejected* — a specific claim about the
     * merchant's configuration that neither condition supports. Publishing it
     * is the same defect as reading a fault envelope as proof of a pass, in the
     * same direction: confidently wrong.
     *
     * The second is currently unreachable on this probe, and the reason is
     * structural rather than incidental. The transport raises it only when the
     * request is not retryable, and retryability is `isIdempotent()` and not on
     * the transport's never-retry list. GetPaymentId answers `true` — read-only,
     * so retryable, per CONVENTIONS.md §4.5 — and is not on that list, so a
     * network failure here becomes a TransportException instead. The clause is
     * kept so that if that ever changes upstream, the outcome degrades to an
     * honest 2 rather than escaping as a stack trace and an exit 1.
     *
     * They share one clause rather than getting a tailored message each because
     * a second, permanently unenterable clause would be uncoverable code, and
     * this package's line-coverage floor is 100. Nothing is lost: the frame
     * below is true of both, and the specific condition arrives in the client's
     * own words through the exception's message — which is more careful than a
     * paraphrase here would be, and which the client guarantees never contains
     * the response body (CONVENTIONS.md §6, and the raw body is unvalidated
     * remote content that must not reach a terminal or a log).
     */
    private function unreadable(string $message): int
    {
        $this->warn(sprintf(
            'Inconclusive. The exchange did not produce a reply this command could read, so nothing about the '
            .'credentials was established — neither that they work nor that they do not. Nothing of the reply '
            .'itself is printed: it is unvalidated remote content. %s Re-run; if it persists, report the '
            .'operation, the time and the environment to Ameriabank — nothing here points at your configuration.',
            $message,
        ));

        return self::INVALID;
    }

    /**
     * The request was never built, because the configuration would not resolve.
     */
    private function misconfigured(string $message): int
    {
        $this->error(sprintf('The Ameriabank vPOS configuration is not usable. %s', $message));

        return self::FAILURE;
    }

    /**
     * The run failed in a way this command does not classify.
     *
     * Defence in depth, and the reason is the exit code rather than the
     * exception. Anything not named in the clauses above would otherwise reach
     * Laravel's default handling, which prints a stack trace and exits **1** —
     * and exit 1 in this command's contract is the specific claim that the
     * gateway refused these credentials. Nothing was established here, so 2 is
     * the honest code, and the message says nothing about credentials in
     * either direction.
     *
     * Two routes reach it today. One is configuration: a `back_url` naming a
     * route that takes required parameters makes the URL generator raise a
     * framework exception the resolver does not convert. The other is any
     * exception a merchant's own PSR-18 client raises that the client package's
     * transport does not wrap.
     *
     * **The class is named and the message is not.** A class name is a fixed
     * string chosen by whoever wrote the class, and it is what makes the
     * failure searchable. A message is composed at the throw site out of
     * whatever was in scope there, and this command has no way to know what an
     * arbitrary component put in it — a client that appends the request it was
     * sending would append the credentials with it. This package's own
     * exceptions have their messages printed because this package knows what
     * they contain; an unclassified one gets no such assumption.
     */
    private function unexpected(string $class): int
    {
        $this->warn(sprintf(
            'Inconclusive. The run failed with an unexpected %s before it could establish anything, so nothing '
            .'about the credentials was learned — neither that they work nor that they do not. The failure\'s own '
            .'message is not printed, because an unclassified failure can carry whatever was in scope where it was '
            .'raised. The class named above is the component that raised it: check what this command printed '
            .'before this line, and the configuration value that component reads, then re-run.',
            $class,
        ));

        return self::INVALID;
    }

    /**
     * The client refused the configured attempt budget.
     *
     * The only ValidationException this exchange can raise: the OrderID is an
     * integer by signature, and a blank credential is refused as a
     * configuration error rather than a validation one. So it is always
     * `max_attempts`, which comes from the package configuration file and from
     * nowhere else — a configuration mistake, reported as one and named, rather
     * than left to escape as an unrendered stack trace.
     *
     * The value reported is the one the client was actually given, which is 0
     * for anything that is not an integer; the configured text is not echoed
     * back, because what matters is what reached the client.
     */
    private function attemptBudgetRefused(Repository $config, string $message): int
    {
        $value = $config->get(self::CONFIG_KEY.'.max_attempts');

        $this->error(sprintf(
            'The Ameriabank vPOS configuration is not usable. ameriabank-vpos.max_attempts '
            .'(AMERIABANK_VPOS_MAX_ATTEMPTS) reached the client as %d, and the client refused it. %s',
            is_int($value) ? $value : 0,
            $message,
        ));

        return self::FAILURE;
    }

    /**
     * The configured environment, or a refusal naming what was configured.
     *
     * Resolved here as well as in the provider because the base URL is part of
     * what this command reports and the client exposes no way back to the
     * environment it was built with. Both call sites raise the same named
     * factory, so there is one message and not two.
     *
     * @throws ConfigurationException on any value the client does not know
     */
    private function environment(Repository $config): Environment
    {
        $value = $this->configString($config, 'environment');

        return Environment::tryFrom($value)
            ?? throw ConfigurationException::unknownEnvironment($value);
    }

    /**
     * A credential, shortened to the point where it identifies without
     * disclosing.
     *
     * The width is a named local rather than a class constant because a
     * `const` declaration is not an executable statement, so it appears in no
     * coverage report and the mutation tool classifies every mutant on its
     * initialiser as uncovered without running a test. As a local it sits on a
     * covered line, where the suite that already pins the four-character
     * output kills those mutants.
     */
    private function masked(Repository $config, string $key): string
    {
        $visibleCharacters = 4;

        $value = $this->configString($config, $key);

        return $value === ''
            ? self::NOT_SET
            : substr($value, 0, $visibleCharacters).self::TRUNCATED;
    }

    /**
     * Whether a value is configured, for the one value that may never be shown.
     *
     * The password is the only credential this command reports on without
     * reading any part of it into a message. Knowing it is missing is the
     * common case and is worth reporting; knowing anything more about it is
     * not worth the risk of a screenshot.
     */
    private function presence(Repository $config, string $key): string
    {
        return $this->configString($config, $key) === '' ? self::NOT_SET : self::SET;
    }

    /**
     * A package configuration value as a string.
     *
     * Anything that is not a string — a missing key, an unset environment
     * variable arriving as null — reads as blank, and blank is refused by
     * whichever component was asked for it.
     */
    private function configString(Repository $config, string $key): string
    {
        $value = $config->get(self::CONFIG_KEY.'.'.$key);

        return is_string($value) ? $value : '';
    }

    /**
     * The gateway's own text, or a marker saying it sent none.
     *
     * An empty string here means the field was absent from the response, which
     * this operation does routinely — a call that succeeded has been observed
     * carrying an empty ResponseMessage. It must not read as this package
     * having dropped something the gateway said.
     */
    private function orPlaceholder(string $message): string
    {
        return $message === '' ? self::NO_MESSAGE : $message;
    }

    private function detail(string $label, string $value): void
    {
        $this->line($label.': '.$value);
    }
}
