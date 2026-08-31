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
use DavitVardanyan\AmeriabankVpos\Laravel\Support\ConfigReader;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;

use function filter_var;
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

    /**
     * Printed for a key that is configured, and configured to the wrong type.
     *
     * Distinct from NOT_SET, and the distinction is the whole of what this
     * line can safely say. An operator reading "(not set)" over a key they
     * filled in goes looking for a missing value; one reading this goes
     * looking for a quoted one. Neither the value nor its length nor any
     * character of it is disclosed by either.
     */
    private const string NOT_A_STRING = '(not a string)';

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
     * **This method takes no parameters, and resolves what it needs from
     * `$this->laravel`.** Two reasons, and the second is the one that decided
     * it.
     *
     * The first is the one that was already written here about the client:
     * everything this method uses is resolved inside the try, because building
     * it is where a blank credential and an out-of-range attempt budget are
     * refused, and a refusal that happened during method injection would
     * escape before this command could report it as the configuration problem
     * it is. A parameter list is resolved before the first statement runs, so
     * anything named in it is outside every catch clause below by
     * construction.
     *
     * The second is what an escaping exception carries. The eight statements
     * above the try can still throw — they write to the output, and an output
     * whose stream has gone away raises, which `vpos:check | head -1` is enough
     * to produce. Such a throw escapes with this method's own frame attached,
     * and with `zend.exception_ignore_args=0` a reporter that walks
     * `getTrace()` and serialises argument objects reads that frame's
     * arguments. While this method took the container, the live password was
     * one hop from a frame this package declares. It no longer takes anything,
     * so that frame now carries nothing.
     *
     * **That narrows the exposure; it does not end it.** Laravel calls this
     * method through `Container::call()`, and those frames carry both the
     * container and this command object, from which `getLaravel()` reaches the
     * same configuration. They belong to the framework and this package cannot
     * remove them. What is true after this change is the smaller claim: no
     * frame *this package declares* hands a reporter the configuration.
     *
     * Resolving `BackUrlResolver` here rather than taking it is behaviourally
     * identical for every path that has ever been observed. It is bound with
     * `bind`, not `singleton` — deliberately, so that it never caches a
     * configuration repository that has since been replaced — so a fresh
     * instance per resolution is what the binding is for, and one built at the
     * BackURL line is the same object one built during method injection would
     * have been. The refusals it raises come out of `resolve()`, which is
     * called in the same place as before, so the order in which this command
     * reports things is unchanged.
     *
     * The success verdict is returned from inside the try as well, so that the
     * environment resolved a few lines above is still in scope for it without
     * a variable that has to be given a placeholder value first — a placeholder
     * assigned before the try and overwritten inside it is unobservable, and an
     * unobservable value is one no test can pin.
     *
     * **The mode is computed once and handed to every inconclusive branch.**
     * Those branches say what was not established and what to do about it, and
     * a run that was blind has a second thing to do about it whatever went
     * wrong: no reply that run could have received would have proved the
     * credentials valid, because the cell it probed is unobserved under both
     * credential states. That is a fact about the invocation rather than about
     * the failure, so it belongs in the branch's own output rather than in the
     * mode note the operator read several lines and one send earlier — the
     * same reasoning `proven()` gives for restating its ownership premise.
     *
     * **The try was deliberately not moved up over those eight statements.**
     * It would not make this method escape-free, which is the only thing that
     * would have been worth the change: the terminal clause answers with
     * `unexpected()`, which writes to the output, so an output failure caught
     * there raises a second time from inside the catch and escapes anyway.
     * Moving it would also put `$blind` inside the try while every catch clause
     * reads it, which forces a placeholder assigned above the try and
     * overwritten inside it — the unobservable value this method already
     * refuses to introduce for the environment, three paragraphs up. So the
     * boundary stays where it is, and the exposure was closed by removing what
     * the escaping frame carries instead.
     *
     * `unusableOrderId()` is not one of them and takes no mode. It returns
     * above, before any mode note is printed, and names the option in its own
     * message; there is no scrollback for it to depend on.
     *
     * The last clause catches `Throwable`, and it is ordered last so that it
     * cannot shadow the specific ones. Everything above it names a condition
     * this command understands; that one exists because an exception nobody
     * listed would otherwise leave the command on Laravel's default handling,
     * which prints a stack trace and exits **1** — and exit 1 here is the
     * specific claim that the gateway refused these credentials. No path
     * through this package's own configuration reaches it any more: a route
     * name in `back_url` that needs parameters did, and is now converted by
     * the resolver into a named configuration failure like every other
     * `back_url` mistake.
     */
    public function handle(): int
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
        $blind = $requestedOrderId === null;

        $this->line($requestedOrderId === null
            ? $this->blindModeNote($sentinelOrderId)
            : $this->orderModeNote($requestedOrderId));

        $this->warn(self::NO_RELIABLE_CHECK);

        try {
            $environment = $this->environment();

            $this->detail('Environment', $environment->value);
            $this->detail('Base URL', $environment->restBaseUrl());
            $this->detail('ClientID', $this->masked('client_id'));
            $this->detail('Username', $this->masked('username'));
            $this->detail('Password', $this->presence('password'));
            $this->detail('BackURL', $this->laravel->make(BackUrlResolver::class)->resolve());

            $this->line(sprintf('Sending one %s request for OrderID %d.', self::PROBE_OPERATION, $orderId));

            $this->laravel->make(Vpos::class)->payments()->paymentIdForOrder($orderId);

            return $requestedOrderId === null
                ? $this->blindSuccess($sentinelOrderId)
                : $this->proven($requestedOrderId, $environment);
        } catch (AuthenticationException $failure) {
            return $this->rejected($failure->responseCode(), $failure->responseMessage());
        } catch (ApiException $failure) {
            return $this->apiAnswer($failure, $blind);
        } catch (GatewayFaultException $failure) {
            return $this->faulted($failure->getMessage(), $blind);
        } catch (TransportException $failure) {
            return $this->unreachable($failure->getMessage(), $blind);
        } catch (SerializationException|IndeterminateStateException $failure) {
            return $this->unreadable($failure->getMessage(), $blind);
        } catch (ClientConfigurationException|ConfigurationException $failure) {
            return $this->misconfigured($failure->getMessage());
        } catch (ValidationException $failure) {
            return $this->configuredValueRefused($failure->getMessage());
        } catch (Throwable $failure) {
            return $this->unexpected($failure::class, $blind);
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
     *
     * The blind pointer is added to that line and not to the rejection above
     * it: a rejection is a verdict, and the mode does not change it.
     */
    private function apiAnswer(ApiException $failure, bool $blind): int
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

        $this->pointBlindRunAtOrderId($blind);

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
    private function faulted(string $message, bool $blind): int
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

        $this->pointBlindRunAtOrderId($blind);

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
    private function unreachable(string $message, bool $blind): int
    {
        $this->warn(sprintf(
            'Inconclusive. Could not reach the gateway, so nothing was learned about the credentials. %s Check '
            .'network egress from this host to the base URL printed above — a proxy, an egress allowlist and DNS '
            .'are the usual three — and re-run.',
            $message,
        ));

        $this->pointBlindRunAtOrderId($blind);

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
    private function unreadable(string $message, bool $blind): int
    {
        $this->warn(sprintf(
            'Inconclusive. The exchange did not produce a reply this command could read, so nothing about the '
            .'credentials was established — neither that they work nor that they do not. Nothing of the reply '
            .'itself is printed: it is unvalidated remote content. %s Re-run; if it persists, report the '
            .'operation, the time and the environment to Ameriabank — nothing here points at your configuration.',
            $message,
        ));

        $this->pointBlindRunAtOrderId($blind);

        return self::INVALID;
    }

    /**
     * Either package raised its own named configuration refusal.
     *
     * **The preamble says what the catch establishes, and no more.** It used to
     * open *"The Ameriabank vPOS configuration is not usable."*, which is true
     * of most of what lands here and false of one case: `httpClientNotPsr18()`
     * is raised for a **container binding**, and this package's README says of
     * that key that it is *"a container key, not a configuration key … not read
     * from `config/ameriabank-vpos.php` and does not appear there"*. The old
     * sentence therefore sent the operator to a file the offending value cannot
     * be in — the same shape as the claim `configuredValueRefused()` withdrew,
     * and against the same rule. *"Not set up correctly"* covers a setting, a
     * container binding and an environment variable without asserting which,
     * and the message that follows names it.
     *
     * **It does not say that nothing was sent, and that omission is deliberate.**
     * The obvious replacement — *"the client could not be built, so no request
     * was sent"* — is not established by this catch. The client's own
     * `ConfigurationException` is reachable **after** a send: `ResponseCode`
     * raises `successCodeHasNoException()` while reading a reply, and
     * `HttpTransport` raises `requestRejectedByClient()` out of a PSR-18
     * client's own failure. Every route reachable today may well precede the
     * send, but that is a reading of one version of a separately versioned
     * package, and *"nothing was sent"* printed over a run that sent something
     * is the stronger and more damaging error — `configuredValueRefused()`
     * records the same reasoning at greater length.
     *
     * **The preamble is not branched by factory either.** The message that
     * follows is written by the factory, which knows whether it is naming a
     * configuration key or a container key and says so; a preamble that guessed
     * would be a second, less-informed account of the same failure. Branching
     * on the message text would be worse: it would couple this command to
     * sentences the core package is free to reword.
     */
    private function misconfigured(string $message): int
    {
        $this->error(sprintf(
            'Ameriabank vPOS is not set up correctly, and this run stopped without reaching a verdict on the '
            .'credentials. %s',
            $message,
        ));

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
     * One route reaches it today: any exception a merchant's own PSR-18 client
     * raises that the client package's transport does not wrap. There was a
     * second, from configuration — a `back_url` naming a route that takes
     * required parameters — and it has been closed, because the resolver now
     * converts that into a named configuration failure. A clause that exists
     * for the failures nobody listed is not made redundant by the list growing;
     * it is the reason the list can grow safely.
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
    private function unexpected(string $class, bool $blind): int
    {
        $this->warn(sprintf(
            'Inconclusive. The run failed with an unexpected %s before it could establish anything, so nothing '
            .'about the credentials was learned — neither that they work nor that they do not. The failure\'s own '
            .'message is not printed, because an unclassified failure can carry whatever was in scope where it was '
            .'raised. The class named above is the component that raised it: check what this command printed '
            .'before this line, and the configuration value that component reads, then re-run.',
            $class,
        ));

        $this->pointBlindRunAtOrderId($blind);

        return self::INVALID;
    }

    /**
     * Tells a blind run that the other mode is the one that could have answered.
     *
     * Printed as its own line rather than appended to the branch above it. The
     * alternative — a `%s` at the end of each message holding either the clause
     * or an empty string — puts a `''` literal in the source on the path that
     * must print nothing, and an empty string that silently becomes a non-empty
     * one is invisible to an assertion written as "does not contain the
     * pointer". A statement that is either executed or not is observable from
     * both sides.
     *
     * **It goes on all five inconclusive branches, including the two where a
     * re-run in the other mode plainly will not help.** An unroutable host
     * stays unroutable and a fault stays a fault, so the clause is written to
     * claim only what is true of every one of them: this run could not have
     * proved the credentials valid whatever it received, because the blind
     * probe asks about an OrderID no merchant registered and the reply to one
     * of those is unobserved under both credential states. Withholding it from
     * some rows would mean deciding, per condition, whether the operator's next
     * attempt is worth making — a judgement this command cannot make for them,
     * and one whose absence reads exactly like the gap this clause closes.
     *
     * Not printed on the rejection, the proven verdict or the configuration
     * refusals: each of those is an answer, and the mode does not change it.
     * Not printed by `unusableOrderId()` either — that path returns before any
     * mode is chosen and names the option itself.
     *
     * Not printed by `blindSuccess()` either, which is a sixth inconclusive row
     * rather than one of the five above. It can arise in no other mode, so a
     * clause conditional on the mode would be an unconditional one wearing a
     * disguise, and its own message already ends by naming the option. The
     * exclusion is recorded here because this is the list a reader consults,
     * and an enumeration that omits a case reads as an oversight rather than
     * as a decision.
     */
    private function pointBlindRunAtOrderId(bool $blind): void
    {
        if ($blind) {
            $this->warn(
                'This run was also blind: no answer it could have received would have proved the credentials '
                .'valid, because the reply to an OrderID the gateway does not know is unobserved under both '
                .'credential states. Re-run with --order-id set to an order you registered — that is the only '
                .'mode whose answer can prove them.',
            );
        }
    }

    /**
     * The client refused something this command cannot name.
     *
     * The only `ValidationException` branch there is. It was the fallback
     * behind a named attempt-budget branch until the attempt budget moved to
     * this side of the bridge: `AmeriabankVposServiceProvider::maxAttempts()`
     * now refuses an out-of-range budget before `new Vpos(...)` is entered, so
     * the client's own refusal of that value can no longer be raised, and the
     * branch that recognised it by rebuilding the client's message would have
     * been unreachable code guarding against a message it could never see.
     * What arrives here is what it always was — a refusal this package did not
     * anticipate — and it is now the whole of what a `ValidationException`
     * means to this command.
     *
     * No key and no environment variable is named, because naming one would be
     * the assumption this branch exists to stop making. **Nothing is claimed
     * about what was refused either.** `blankValue()` and `malformedValue()`
     * name a field and no value at all, and `callbackOrderMismatch()` says
     * outright that neither value is reported — so a sentence promising the
     * rejected value would be false for several of the factories the client
     * ships, in the one line an operator acts on.
     *
     * **Nor about when it was refused.** Every factory reachable on this
     * exchange today raises before the request is dispatched, but that is a
     * reading of one version of a separately versioned package, not something
     * this command checks. `GetPaymentIdResponse` is built from the reply
     * *after* the send on this very call path, and the client already raises a
     * `ValidationException` after a send elsewhere, in `Vpos::verify()`.
     * "Nothing was sent", printed about a run that sent something, is a
     * stronger and more damaging claim than naming the wrong key.
     *
     * **Nor that the refused value came from configuration**, which is what
     * the opening sentence used to claim. It read "The Ameriabank vPOS
     * configuration is not usable." over a paragraph explaining that this
     * command cannot tell whether a setting was involved at all — the first
     * sentence contradicting the second, with the first being the one an
     * operator acts on. What the opening says now is what the catch clause
     * establishes and no more: the client raised its own named refusal, and
     * the run stopped without reaching a verdict on the credentials.
     *
     * **The client's own words are still printed whole, and the reason is not
     * that their content is known.** They belong to the *client* package's
     * exception type, not this one's, and taking its contents as known is the
     * assumption this branch exists to end. They are printed because it is
     * classified: the client raised its own named refusal, deliberately, at a
     * point it chose. `unexpected()` withholds the message of an exception
     * nobody classified for the opposite reason — that one is composed at a
     * throw site out of whatever happened to be in scope there. The words are
     * the only evidence this branch has, so they are passed through unedited
     * and every sentence around them is written to claim nothing about them.
     *
     * **Exit 1 is unchanged, and the sentence changing does not change it.**
     * The exit code says what the run can do, not where the fault lies: the
     * run cannot proceed, it produced no verdict about the credentials, and
     * something the merchant controls — a setting, a bound HTTP client, an
     * `--order-id` — was refused by a component that named its own refusal.
     * Exit 2 is this command's code for "nothing was established about the
     * credentials, re-run"; there is nothing to re-run here, because the same
     * refusal will arrive again until something changes. So the code stays 1
     * and only the claim about the cause is withdrawn.
     */
    private function configuredValueRefused(string $message): int
    {
        $this->error(sprintf(
            'The Ameriabank vPOS client refused an input, and this run stopped without reaching a verdict on '
            .'the credentials. This command cannot tell what was refused, which setting the refused input came '
            .'from, or whether it came from a setting at all — the client\'s own words are the whole of what is '
            .'known here. %s Check the ameriabank-vpos configuration against that message; if nothing there '
            .'matches, the refusal is not '
            .'about a value you set, and this output is worth reporting as a defect in this package rather than '
            .'a mistake in your configuration.',
            $message,
        ));

        return self::FAILURE;
    }

    /**
     * The configured environment, or a refusal naming what was configured.
     *
     * Resolved here as well as in the provider because the base URL is part of
     * what this command reports and the client exposes no way back to the
     * environment it was built with. Both call sites read through the same
     * `ConfigReader` and raise the same named factory, so there is one reading
     * and one message rather than two of each.
     *
     * @throws ConfigurationException on any value the client does not know
     */
    private function environment(): Environment
    {
        $value = $this->packageConfig()->string('environment');

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
     *
     * A non-string value gets a placeholder rather than a refusal. This method
     * only prints a line, and the run is going to stop below — when the
     * provider reads the same key and refuses it by name, **unless an earlier
     * line refuses first**. Throwing from here would withhold the environment,
     * the base URL and the other credentials from an operator who is about to
     * be told one of them is the wrong type. Reporting every line and then
     * failing is the more informative order, and it is the order the whole
     * command is written in.
     *
     * The carve-out is real and is what a merchant with two mistakes at once
     * hits. `handle()` prints ClientID, Username and Password, then resolves
     * BackURL, and only then builds the client — so a `back_url` that is
     * blank, unresolvable or parameterised raises from
     * `BackUrlResolver::resolve()` *between* the placeholder and the refusal
     * this paragraph points at. The operator then sees the row named as
     * `(not a string)` and a refusal about a different key, and is never told
     * which type the first one holds. The placeholder is still the honest
     * account of the row; it is one refusal short of the whole account.
     */
    private function masked(string $key): string
    {
        $visibleCharacters = 4;

        $value = $this->packageConfig()->value($key);

        if (! is_string($value)) {
            return $this->absentOrMistyped($value);
        }

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
     *
     * A wrong-typed password is worth reporting for the same reason as any
     * other key, and reporting it discloses nothing this method was not
     * already disclosing: "(set)" and "(not a string)" both say the key holds
     * something, and neither says what.
     */
    private function presence(string $key): string
    {
        $value = $this->packageConfig()->value($key);

        if (! is_string($value)) {
            return $this->absentOrMistyped($value);
        }

        return $value === '' ? self::NOT_SET : self::SET;
    }

    /**
     * Which placeholder a value that is not a string gets.
     *
     * The distinction this command exists to stop losing. `null` is the shape
     * a missing key and an unset environment variable both arrive in, and it
     * really is absent. Anything else is present and typed wrongly, and
     * printing "(not set)" over it sends the operator to look for a value they
     * already supplied.
     *
     * The value is read for nothing but which of those two it is. Its type is
     * not printed here either — the refusal the provider raises a moment later
     * names the key and the type together, in one place, where the sentence
     * has room to say what to do about it. That refusal arrives on the
     * ordinary path and is not guaranteed: `masked()`'s docblock records the
     * case where `BackUrlResolver::resolve()` refuses first and the type is
     * never named.
     */
    private function absentOrMistyped(mixed $value): string
    {
        return $value === null ? self::NOT_SET : self::NOT_A_STRING;
    }

    /**
     * A reader over the configuration repository the container holds *now*.
     *
     * **This command must read a key exactly as the service provider reads
     * it.** The provider is what builds the client, so a key this command
     * accepted and the provider refused — or the reverse — would print one
     * account of the configuration and act on another, and the two accounts
     * would arrive in the same run: `Environment: ` printed from a blank
     * reading, then a refusal from the provider four lines later.
     *
     * That used to be a requirement stated in prose, held up by this command
     * carrying an eight-line reader byte-identical to the provider's. It is
     * now a requirement held by there being one reader. `ConfigReader` carries
     * the reasoning for how a value is coerced, and why the configured value
     * never crosses into an exception factory.
     *
     * A fresh reader per read rather than one held on the command. An artisan
     * command instance is cached by the console application and can run more
     * than once in a process — a test calling `artisan('vpos:check')` twice
     * with different configuration between the calls does exactly that — so a
     * reader stored here would report the configuration of the earlier run.
     *
     * `masked()` and `presence()` read through `value()` rather than
     * `string()` deliberately: they print a line for a value they have been
     * told to describe rather than to use, and their placeholder is the
     * description.
     */
    private function packageConfig(): ConfigReader
    {
        return new ConfigReader($this->laravel->make(Repository::class));
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
