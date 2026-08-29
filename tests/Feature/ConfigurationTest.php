<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException as ClientConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Tests\Support\ClientInternals;
use DavitVardanyan\AmeriabankVpos\Vpos;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

beforeEach(function (): void {
    vposConfig();

    Config::set('logging.channels.vpos_probe', [
        'driver' => 'single',
        'path' => storage_path('logs/vpos-probe-that-is-never-written.log'),
    ]);
});

it('refuses an environment the client does not know, naming the value', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 'staging']);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'Unknown Ameriabank vPOS environment "staging". Set ameriabank-vpos.environment '
        .'(AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.',
    );
});

it('refuses an environment that is not a string at all, and says it read a blank one', function (): void {
    vposConfig(['ameriabank-vpos.environment' => 42]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ConfigurationException::class,
        'Unknown Ameriabank vPOS environment "". Set ameriabank-vpos.environment '
        .'(AMERIABANK_VPOS_ENVIRONMENT) to one of: test, production.',
    );
});

it('builds a client for either environment the package knows', function (string $environment): void {
    vposConfig(['ameriabank-vpos.environment' => $environment]);

    expect(app(Vpos::class))->toBeInstanceOf(Vpos::class);
})->with(['test', 'production']);

it('refuses a credential the application never configured', function (): void {
    vposConfig(['ameriabank-vpos.client_id' => null]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ClientConfigurationException::class,
        'Credential field "ClientID" must not be blank.',
    );
});

it('refuses a credential that is not a string, rather than coercing it', function (): void {
    vposConfig(['ameriabank-vpos.username' => 12345]);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ClientConfigurationException::class,
        'Credential field "Username" must not be blank.',
    );
});

it('refuses an attempt budget that is not an integer, rather than coercing it', function (): void {
    vposConfig(['ameriabank-vpos.max_attempts' => '3']);

    expect(fn (): Vpos => app(Vpos::class))->toThrow(
        ValidationException::class,
        'The maximum attempt count must be between 1 and 5, got 0.',
    );
});

it('passes an integer attempt budget through to the client', function (): void {
    vposConfig(['ameriabank-vpos.max_attempts' => 5]);

    expect(app(Vpos::class))->toBeInstanceOf(Vpos::class);
});

it('gives the client a null logger while logging is off', function (): void {
    expect(ClientInternals::loggerOf(app(Vpos::class)))->toBeInstanceOf(NullLogger::class);
});

it('leaves logging off for anything that is not exactly true', function (): void {
    vposConfig(['ameriabank-vpos.logging.enabled' => 1]);

    expect(ClientInternals::loggerOf(app(Vpos::class)))->toBeInstanceOf(NullLogger::class);
});

it('gives the client the configured channel once logging is on', function (): void {
    vposConfig([
        'ameriabank-vpos.logging.enabled' => true,
        'ameriabank-vpos.logging.channel' => 'vpos_probe',
    ]);

    $logger = ClientInternals::loggerOf(app(Vpos::class));

    expect($logger)->toBe(Log::channel('vpos_probe'))
        ->and($logger)->not->toBeInstanceOf(NullLogger::class);
});

it('gives the client the default channel when logging is on and none is named', function (): void {
    vposConfig(['ameriabank-vpos.logging.enabled' => true]);

    $logger = ClientInternals::loggerOf(app(Vpos::class));

    expect($logger)->toBe(Log::channel())
        ->and($logger)->not->toBeInstanceOf(NullLogger::class)
        ->and($logger)->not->toBe(Log::channel('vpos_probe'));
});
