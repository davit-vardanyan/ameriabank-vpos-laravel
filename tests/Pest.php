<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\RefusingClientStrategy;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\TestCase;
use Http\Discovery\ClassDiscovery;
use Illuminate\Support\Facades\Config;

uses(TestCase::class)->in('Feature');

/*
 * No test in this suite may reach the network.
 *
 * The provider hands the client whatever PSR-18 implementation the container
 * holds, and null when it holds none — at which point php-http/discovery
 * chooses, and in this vendor tree it would choose a real Guzzle client. That
 * path is reached deliberately by one test and accidentally by any mutant that
 * flips the container check, so the discovered answer is replaced here rather
 * than in a test that has to remember to.
 *
 * The strategy is prepended, not set: PSR-17 factory discovery still runs
 * through the package's normal strategies, and only the PSR-18 answer changes.
 */
ClassDiscovery::prependStrategy(RefusingClientStrategy::class);

/**
 * The clause every inconclusive branch adds when the run was blind.
 *
 * Written out rather than read from the command, for the same reason the
 * standing caveat is: this is what the package has committed to telling an
 * operator whose run could not have proved anything, and a sentence that
 * changes whenever the code changes is not a commitment.
 *
 * It is asserted in both directions, and on both sides of the line the
 * command's own docblock draws. Present on every inconclusive branch of a
 * blind run; absent from those same branches in the --order-id mode, where
 * there is nothing further to suggest; and absent from every branch
 * `CheckCommand::pointBlindRunAtOrderId()` lists as an exclusion, blind run or
 * not.
 *
 * **It lives here rather than beside the other message constants in
 * CheckCommandTest.php because two files assert it.** The generic
 * configuration refusal is reachable only through the PSR-18 seam, so its
 * exclusion is pinned in CheckCommandExceptionCoverageTest.php, and a Pest run
 * given a single file loads that file and this one and no other — verified,
 * not assumed. A second copy of the sentence in the other file would be a
 * literal free to drift from this one in the direction where both tests still
 * pass.
 *
 * **A function rather than a `const`, and that is forced rather than chosen.**
 * PHPStan resolves a global function defined in an analysed file from any
 * other file, and does not do the same for a global constant: moving the
 * sentence here as a `const` left level 10 reporting `constant.notFound` at
 * every one of its nine call sites. The other message constants stay where
 * they are because only one file reads each of them.
 */
function blindPointer(): string
{
    return 'This run was also blind: no answer it could have received would have proved the credentials valid, '
        .'because the reply to an OrderID the gateway does not know is unobserved under both credential states. '
        .'Re-run with --order-id set to an order you registered — that is the only mode whose answer can prove '
        .'them.';
}

/**
 * Configuration for a package that is wired but has never been near a bank.
 *
 * The credentials are self-describing non-credentials. They are shaped so the
 * command's masking is visible in an assertion — the first four characters of
 * each differ — and so a leak assertion has something unmistakable to look for.
 *
 * @param  array<string, mixed>  $overrides
 */
function vposConfig(array $overrides = []): void
{
    Config::set(array_merge([
        'ameriabank-vpos.client_id' => 'CLIENTID-NOT-A-REAL-CREDENTIAL',
        'ameriabank-vpos.username' => 'USERNAME-NOT-A-REAL-CREDENTIAL',
        'ameriabank-vpos.password' => 'PASSWORD-NOT-A-REAL-CREDENTIAL',
        'ameriabank-vpos.environment' => 'test',
        'ameriabank-vpos.back_url' => 'https://shop.example.test/vpos/back',
        'ameriabank-vpos.max_attempts' => 3,
        'ameriabank-vpos.logging.enabled' => false,
        'ameriabank-vpos.logging.channel' => null,
    ], $overrides));
}
