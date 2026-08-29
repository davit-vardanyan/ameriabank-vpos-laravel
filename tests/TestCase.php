<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel\Tests;

use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Testbench;

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
}
