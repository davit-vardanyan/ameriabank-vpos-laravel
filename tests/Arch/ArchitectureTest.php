<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Facade;

/*
 * Each expectation is passed to arch() as a closure rather than chained off its
 * return value.
 *
 * arch() returns Pest\PendingCalls\TestCall, which resolves expect() through
 * __call(). PHPStan reports magic methods from level 7 upwards, so the chained
 * form ("arch(...)->expect(...)") fails static analysis at level 10 with
 * method.notFound and leaves every subsequent call typed as mixed. Inside a
 * closure, expect() returns Pest\Expectation, whose arch expectations are
 * declared methods, so the analysis is exact. The expectations themselves are
 * unchanged.
 */

arch('everything in src is final', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel')
        ->classes()
        ->toBeFinal();
});

arch('src declares strict types', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel')
        ->toUseStrictTypes();
});

arch('nothing debugs in production', function (): void {
    expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
        ->not->toBeUsed();
});

/*
 * env() returns null once an application has cached its configuration, so a
 * package that reads the environment anywhere but in its config file works in
 * development and silently loses every credential in production. The failure is
 * a blank ClientID reaching the gateway, which answers response code 20 — a
 * message about credentials for a mistake that has nothing to do with them.
 *
 * config/ameriabank-vpos.php is the one file allowed to call it, and it is out
 * of reach of this expectation by construction: it belongs to no namespace, so
 * no PSR-4 prefix in composer.json maps to it and nothing here can see it.
 */
arch('nothing reads the environment outside the configuration file', function (): void {
    expect('env')->not->toBeUsed();
});

arch('the exception namespace holds nothing but exceptions', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Exception')
        ->toImplement(Throwable::class)
        ->toHaveSuffix('Exception');
});

arch('the facade namespace holds nothing but facades', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Facades')
        ->toExtend(Facade::class);
});

arch('the command namespace holds nothing but artisan commands', function (): void {
    expect('DavitVardanyan\AmeriabankVpos\Laravel\Commands')
        ->toExtend(Command::class)
        ->toHaveSuffix('Command');
});
