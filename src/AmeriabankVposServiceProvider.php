<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the Ameriabank vPOS client with a Laravel application.
 *
 * Bindings, configuration, the facade and callback resolution arrive in task
 * 002 onward. This class currently exists to anchor package discovery and the
 * toolchain.
 */
final class AmeriabankVposServiceProvider extends ServiceProvider
{
    /**
     * The core client version this bridge targets.
     */
    public const string TARGETS_CLIENT = '^1.0.1';

    public function register(): void {}
}
