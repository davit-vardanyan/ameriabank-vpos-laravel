<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests;

use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\RefusingHttpClient;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\StubHttpClient;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Testbench;
use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * Base test case for the Feature suite.
 *
 * Testbench boots a minimal Laravel application for each test. The Arch suite
 * needs no application and is deliberately not bound to this class.
 */
abstract class TestCase extends Testbench
{
    /**
     * Register this package's provider with the Testbench application.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AmeriabankVposServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        RefusingHttpClient::forgetAttempts();
    }

    /**
     * Fails the test that tried to leave the machine.
     *
     * RefusingHttpClient throws where the attempt is made, and that used to be
     * the whole mechanism: an ordinary RuntimeException passed through every
     * catch clause in the client package and failed the test on the spot.
     * `CheckCommand::handle()` now ends in a `catch (Throwable)` clause. That
     * clause is right — an unclassified failure must not leave that command on
     * exit 1, which in its contract means the gateway refused the merchant's
     * credentials — but it catches this refusal along with everything else and
     * converts it into exit 2 and an "unexpected RuntimeException" line, which
     * is an outcome several tests assert deliberately. A `vpos:check` test that
     * forgot to bind a stub would have gone green while reaching for the bank:
     * green in the direction that hides the defect.
     *
     * So the attempt is written to a log before it is thrown, and read back
     * here, where no catch clause can get at it.
     *
     * **In tearDown() and not in a Pest hook.** A `beforeEach()`/`afterEach()`
     * pair in tests/Pest.php was tried first and does not run at all unless it
     * is scoped with `->in('Feature')` — which is reached through Pest's
     * `__call` and so fails PHPStan at the level this package analyses, the same
     * obstacle the arch expectations work around. A hook that silently does not
     * run is worse than no hook, since it reads as protection. `tearDown()` is
     * an ordinary method on the class every Feature test already extends, it
     * cannot fail to be called, and it type-checks.
     *
     * The parent runs first so that the application is torn down whatever this
     * finds.
     */
    protected function tearDown(): void
    {
        $attempts = RefusingHttpClient::attempts();

        RefusingHttpClient::forgetAttempts();

        parent::tearDown();

        if ($attempts !== []) {
            throw new RuntimeException(sprintf(
                'This test tried to send %d real HTTP request(s): %s. No test in this suite may reach the '
                .'network. Bind a %s into the container before the exchange. The refusal was raised where the '
                .'attempt was made and something caught it — CheckCommand::handle() ends in a catch (Throwable) '
                .'clause — so it is reported here rather than being lost.',
                count($attempts),
                implode('; ', $attempts),
                StubHttpClient::class,
            ));
        }
    }
}
