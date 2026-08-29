<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use Illuminate\Foundation\Application;

it('is registered in the booted application', function (): void {
    /** @var Application $app */
    $app = app();

    expect($app->isBooted())->toBeTrue()
        ->and($app->providerIsLoaded(AmeriabankVposServiceProvider::class))->toBeTrue()
        ->and(array_keys($app->getLoadedProviders()))->toContain(AmeriabankVposServiceProvider::class)
        ->and($app->getProvider(AmeriabankVposServiceProvider::class))
        ->toBeInstanceOf(AmeriabankVposServiceProvider::class);
});

it('registers nothing in the container', function (): void {
    /** @var Application $app */
    $app = app();

    $before = array_keys($app->getBindings());

    (new AmeriabankVposServiceProvider($app))->register();

    expect(array_keys($app->getBindings()))->toBe($before);
});

it('targets the core client version the package requires', function (): void {
    expect(AmeriabankVposServiceProvider::TARGETS_CLIENT)->toBe('^1.0.1');
});
