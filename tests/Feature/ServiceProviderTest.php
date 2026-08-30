<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Laravel\AmeriabankVposServiceProvider;
use DavitVardanyan\AmeriabankVpos\Laravel\Commands\CheckCommand;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * The packaged configuration file, located the way the provider locates it.
 */
function packagedConfigPath(): string
{
    return dirname(__DIR__, 2).'/config/ameriabank-vpos.php';
}

/**
 * How many console bootstrappers the framework is holding.
 *
 * ServiceProvider::commands() does exactly one observable thing — it appends a
 * callback to Illuminate\Console\Application's static bootstrapper list — so
 * counting that list before and after a boot is what tells the two sides of
 * the runningInConsole() guard apart. The count is read from the framework's
 * own property rather than from a list kept here.
 */
function consoleBootstrapperCount(): int
{
    $value = (new ReflectionProperty(ConsoleApplication::class, 'bootstrappers'))->getValue();

    if (! is_array($value)) {
        throw new RuntimeException(ConsoleApplication::class.'::$bootstrappers is not an array.');
    }

    return count($value);
}

/**
 * Boots a second copy of the provider with runningInConsole() forced.
 *
 * The flag is cached on the application after its first read, so overwriting
 * it is enough to present the provider with a web request's view of the world.
 * It is restored from the value that was there, not from an assumption about
 * what it was.
 */
function bootProviderWithConsole(bool $runningInConsole): void
{
    /** @var Application $app */
    $app = app();

    $flag = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $original = $flag->getValue($app);
    $flag->setValue($app, $runningInConsole);

    try {
        (new AmeriabankVposServiceProvider($app))->boot();
    } finally {
        $flag->setValue($app, $original);
    }
}

it('is registered in the booted application', function (): void {
    /** @var Application $app */
    $app = app();

    expect($app->isBooted())->toBeTrue()
        ->and($app->providerIsLoaded(AmeriabankVposServiceProvider::class))->toBeTrue()
        ->and(array_keys($app->getLoadedProviders()))->toContain(AmeriabankVposServiceProvider::class)
        ->and($app->getProvider(AmeriabankVposServiceProvider::class))
        ->toBeInstanceOf(AmeriabankVposServiceProvider::class);
});

it('merges the packaged configuration without anything being published', function (): void {
    /** @var Application $app */
    $app = app();

    expect(File::exists($app->configPath('ameriabank-vpos.php')))->toBeFalse()
        ->and(Config::get('ameriabank-vpos'))->toBe(require packagedConfigPath());
});

it('defaults to the test environment, three attempts and no logging', function (): void {
    expect(Config::get('ameriabank-vpos.environment'))->toBe('test')
        ->and(Config::get('ameriabank-vpos.max_attempts'))->toBe(3)
        ->and(Config::get('ameriabank-vpos.logging.enabled'))->toBeFalse()
        ->and(Config::get('ameriabank-vpos.logging.channel'))->toBeNull()
        ->and(Config::get('ameriabank-vpos.client_id'))->toBeNull()
        ->and(Config::get('ameriabank-vpos.username'))->toBeNull()
        ->and(Config::get('ameriabank-vpos.password'))->toBeNull()
        ->and(Config::get('ameriabank-vpos.back_url'))->toBeNull();
});

it('publishes the packaged configuration under the ameriabank-vpos-config tag', function (): void {
    /** @var Application $app */
    $app = app();

    $directory = sys_get_temp_dir().'/ameriabank-vpos-publish-'.bin2hex(random_bytes(8));
    File::makeDirectory($directory);

    try {
        $app->useConfigPath($directory);
        (new AmeriabankVposServiceProvider($app))->boot();

        $published = ServiceProvider::pathsToPublish(AmeriabankVposServiceProvider::class, 'ameriabank-vpos-config');

        expect($published)->toHaveCount(1)
            ->and(array_values($published))->toBe([$directory.'/ameriabank-vpos.php']);

        foreach (array_keys($published) as $source) {
            expect(realpath((string) $source))->toBe(packagedConfigPath());
        }

        expect(Artisan::call('vendor:publish', ['--tag' => 'ameriabank-vpos-config']))->toBe(0);

        expect(File::get($directory.'/ameriabank-vpos.php'))->toBe(File::get(packagedConfigPath()));
    } finally {
        File::deleteDirectory($directory);
    }
});

it('offers the check command to artisan while running in the console', function (): void {
    $before = consoleBootstrapperCount();

    bootProviderWithConsole(true);

    expect(consoleBootstrapperCount())->toBe($before + 1);
});

it('offers artisan nothing while serving a web request', function (): void {
    $before = consoleBootstrapperCount();

    bootProviderWithConsole(false);

    expect(consoleBootstrapperCount())->toBe($before);
});

it('registers the check command under its documented signature and description', function (): void {
    $commands = app()->make(Kernel::class)->all();

    expect($commands)->toHaveKey('vpos:check');

    $command = $commands['vpos:check'];

    if (! $command instanceof CheckCommand) {
        throw new RuntimeException('Something other than this package registered vpos:check.');
    }

    expect($command->getDescription())
        ->toBe('Ask the Ameriabank vPOS gateway what it says about the configured credentials. Makes one real HTTP request.');
});
