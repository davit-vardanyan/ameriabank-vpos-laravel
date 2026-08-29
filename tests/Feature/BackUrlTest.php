<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Laravel\Support\BackUrlResolver;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    vposConfig();

    Route::get('/checkout/vpos/back', static fn (): string => 'ok')->name('checkout.vpos.back');
});

it('passes an absolute https URL through untouched', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'https://shop.example.test/checkout/back?order=17']);

    expect(app(BackUrlResolver::class)->resolve())->toBe('https://shop.example.test/checkout/back?order=17');
});

it('passes an absolute http URL through untouched', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'http://shop.example.test/checkout/back']);

    expect(app(BackUrlResolver::class)->resolve())->toBe('http://shop.example.test/checkout/back');
});

it('resolves a route name through the application URL generator', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.back']);

    expect(app(BackUrlResolver::class)->resolve())->toBe('http://localhost/checkout/vpos/back');
});

it('refuses a route name this application has not registered, naming the value', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.bakc']);

    expect(fn (): string => app(BackUrlResolver::class)->resolve())->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.back_url is "checkout.vpos.bakc", which is neither an absolute http or https URL '
        .'nor the name of a registered route. Name a route, or configure a full URL.',
    );
});

it('reads an upper-case scheme as a route name rather than as a URL', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'HTTPS://shop.example.test/checkout/back']);

    expect(fn (): string => app(BackUrlResolver::class)->resolve())->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.back_url is "HTTPS://shop.example.test/checkout/back", which is neither an '
        .'absolute http or https URL nor the name of a registered route. Name a route, or configure a full URL.',
    );
});

it('refuses a blank back_url', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => '']);

    expect(fn (): string => app(BackUrlResolver::class)->resolve())->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.back_url (AMERIABANK_VPOS_BACK_URL) is blank. Set it to an absolute http or '
        .'https URL, or to the name of a route in your application.',
    );
});

it('refuses a back_url that is only whitespace', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => "  \t\n  "]);

    expect(fn (): string => app(BackUrlResolver::class)->resolve())->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.back_url (AMERIABANK_VPOS_BACK_URL) is blank. Set it to an absolute http or '
        .'https URL, or to the name of a route in your application.',
    );
});

it('refuses a back_url that was never configured at all', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => null]);

    expect(fn (): string => app(BackUrlResolver::class)->resolve())->toThrow(
        ConfigurationException::class,
        'ameriabank-vpos.back_url (AMERIABANK_VPOS_BACK_URL) is blank. Set it to an absolute http or '
        .'https URL, or to the name of a route in your application.',
    );
});

it('names the route lookup failure as the cause of its refusal', function (): void {
    vposConfig(['ameriabank-vpos.back_url' => 'checkout.vpos.bakc']);

    try {
        app(BackUrlResolver::class)->resolve();
    } catch (ConfigurationException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($failure->getPrevious()?->getMessage())->toContain('checkout.vpos.bakc');

        return;
    }

    throw new RuntimeException('The resolver accepted a route name that does not exist.');
});
