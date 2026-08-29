<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Commands;

use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException as ClientConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

use function is_string;
use function sprintf;
use function substr;

/**
 * Asks the gateway whether these credentials work, and says what it answered.
 *
 * Configuration can be complete, well-formed and still wrong: the bank issues
 * separate credentials per environment, and a set that is valid on the sandbox
 * is rejected in production. Nothing local can tell the difference. So this
 * command sends one real request and reads the reply.
 *
 * ## The inversion
 *
 * The probe asks for a payment that does not exist, so the *interesting*
 * answers are all failures. A refusal to discuss an unknown payment means the
 * exchange got far enough for the gateway to have a view about it. What proves
 * the credentials are wrong is response code 20, and — on this operation —
 * nothing else does so unambiguously.
 *
 * ## What the answer does not prove
 *
 * The client's CONVENTIONS.md §4.25 and §4.26 record that this operation has
 * answered a wrong password with the *same* fault envelope, and with the same
 * `"550"`, that correct credentials received against an unknown order. Both
 * replies are therefore the expected outcome of a working setup and neither is
 * proof on its own. The command exits 0 on them because a merchant needs to
 * know the request reached the gateway and was processed, and it says out loud
 * what that does and does not establish rather than reporting a verdict the
 * gateway never gave.
 *
 * ## What is never printed
 *
 * The password is never read into this class at all — only whether the key
 * holds anything. The ClientID and username are truncated to their first four
 * characters, which is enough to tell two credential sets apart in a
 * screenshot and not enough to use.
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vpos:check';

    /**
     * @var string
     */
    protected $description = 'Verify the configured Ameriabank vPOS credentials. Makes one real HTTP request to the gateway.';

    /**
     * The configuration namespace this command reads.
     */
    private const string CONFIG_KEY = 'ameriabank-vpos';

    /**
     * The payment the probe asks about.
     *
     * A 36-character GUID, so it is the shape the gateway issues, and all
     * zeroes, so it cannot collide with a payment any merchant holds. It is a
     * sentinel and not an example of a real identifier.
     */
    private const string PROBE_PAYMENT_ID = '00000000-0000-0000-0000-000000000000';

    /**
     * The operation the probe uses, for the line that announces it.
     *
     * Display text mirroring the client's own request model. Nothing branches
     * on it; the outcome messages below take the operation from the failure
     * they are reporting.
     */
    private const string PROBE_OPERATION = 'GetPaymentDetails';

    /**
     * The credential rejection code in its string form.
     *
     * The client classifies only the integer 20 as an authentication failure,
     * deliberately, because the string form is overloaded across endpoints.
     * This command asks exactly one endpoint, where the overloaded meaning
     * cannot arise, and a credential check must not read a rejection as a pass.
     */
    private const string REJECTED_CODE_AS_TEXT = '20';

    private const string TRUNCATED = '...';

    private const string NOT_SET = '(not set)';

    private const string SET = '(set)';

    private const string NO_MESSAGE = '(no message)';

    /**
     * What a reply to the probe does, and does not, establish.
     */
    private const string AMBIGUITY_NOTE = 'The request reached the gateway and was answered, which is what a working configuration looks like. This operation has been observed answering a rejected password the same way it answers an unknown payment, so treat this as the expected result rather than as proof the credentials are valid. Response code 20 is what proves they are not.';

    /**
     * Resolves the configuration, prints it, then sends the probe.
     *
     * Each value is printed as it is resolved rather than in one block at the
     * end, so a run that dies on an unresolvable `back_url` still shows the
     * environment and base URL it got that far with.
     *
     * The client is resolved inside the try, not injected into this method:
     * building it is where a blank credential is refused, and a refusal that
     * happened during method injection would escape before this command could
     * report it as the configuration problem it is.
     */
    public function handle(Application $app, Repository $config, BackUrlResolver $backUrl): int
    {
        try {
            $environment = $this->environment($config);

            $this->detail('Environment', $environment->value);
            $this->detail('Base URL', $environment->restBaseUrl());
            $this->detail('ClientID', $this->masked($config, 'client_id'));
            $this->detail('Username', $this->masked($config, 'username'));
            $this->detail('Password', $this->presence($config, 'password'));
            $this->detail('BackURL', $backUrl->resolve());

            $this->line(sprintf(
                'Sending one %s request for probe PaymentID %s.',
                self::PROBE_OPERATION,
                self::PROBE_PAYMENT_ID,
            ));

            $app->make(Vpos::class)->payments()->details(self::PROBE_PAYMENT_ID);
        } catch (AuthenticationException $failure) {
            return $this->rejected($failure->responseCode(), $failure->responseMessage());
        } catch (ApiException $failure) {
            return $this->apiAnswer($failure);
        } catch (GatewayFaultException $failure) {
            return $this->ambiguous($failure->getMessage());
        } catch (TransportException $failure) {
            return $this->unreachable($failure->getMessage());
        } catch (ClientConfigurationException|ConfigurationException $failure) {
            return $this->misconfigured($failure->getMessage());
        }

        return $this->accepted(sprintf(
            'The gateway answered %s with a success code for a PaymentID that should not exist. Nothing but an '
            .'authenticated caller gets a success code, so the credentials are good; treat the answer itself as '
            .'suspect and check which environment this ran against.',
            self::PROBE_OPERATION,
        ));
    }

    /**
     * A business failure code, which is either the rejection or evidence
     * against it.
     *
     * The rejection test is delegated to the client's own value object so that
     * a future widening of what it classifies is inherited here rather than
     * missed, and the string form it declines to classify is added on top —
     * that gap is documented in the client, and the safe direction for a
     * credential check is to believe a rejection.
     */
    private function apiAnswer(ApiException $failure): int
    {
        $code = $failure->responseCode();

        if ($this->isCredentialRejection($code)) {
            return $this->rejected($code, $failure->responseMessage());
        }

        return $this->ambiguous(sprintf(
            '%s answered with response code %s: %s',
            $failure->operation(),
            $code,
            $this->orPlaceholder($failure->responseMessage()),
        ));
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
     * The gateway processed the request and refused to discuss the payment.
     *
     * Exit 0, because this is what a working configuration produces — with the
     * caveat attached, because on this operation it is also what a rejected
     * password has produced.
     */
    private function ambiguous(string $detail): int
    {
        $this->info($detail);
        $this->warn(self::AMBIGUITY_NOTE);

        return self::SUCCESS;
    }

    /**
     * The gateway answered the probe successfully.
     *
     * No caveat: a success code is not reachable without having authenticated,
     * so this is the one reply that settles the question on its own.
     */
    private function accepted(string $detail): int
    {
        $this->info($detail);

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
        $this->error(sprintf(
            'Could not reach the gateway, so nothing was learned about the credentials. %s',
            $message,
        ));

        return self::FAILURE;
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
     * this operation does routinely. It must not read as this package having
     * dropped something the gateway said.
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
