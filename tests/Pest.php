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
