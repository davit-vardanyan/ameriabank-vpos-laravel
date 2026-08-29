<?php

declare(strict_types=1);

use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use DavitVardanyan\AmeriabankVpos\Laravel\Exception\ConfigurationException;

/**
 * A real serialization round trip, back as the type it went in as.
 */
function roundTrip(ConfigurationException $failure): ConfigurationException
{
    $restored = unserialize(serialize($failure));

    if (! $restored instanceof ConfigurationException) {
        throw new RuntimeException('A serialized ConfigurationException did not come back as one.');
    }

    return $restored;
}

it('is catchable as anything either package can raise', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    expect($failure)->toBeInstanceOf(VposExceptionInterface::class)
        ->and($failure)->toBeInstanceOf(LogicException::class)
        ->and($failure->getCode())->toBe(0)
        ->and($failure->getPrevious())->toBeNull();
});

it('can only be built through one of its named factories', function (): void {
    expect((new ReflectionClass(ConfigurationException::class))->getConstructor()?->isPrivate())->toBeTrue();
});

it('reports nothing about a dropped chain until it has been through one', function (): void {
    expect(ConfigurationException::blankBackUrl()->chainDropped())->toBeNull()
        ->and(ConfigurationException::unresolvableBackUrlRoute('x', new RuntimeException('y'))->chainDropped())
        ->toBeNull();
});

it('serializes only what it chose to publish', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    expect($failure->__serialize())->toBe([
        'message' => $failure->getMessage(),
        'file' => $failure->getFile(),
        'line' => $failure->getLine(),
        'chainDropped' => false,
    ]);
});

it('records that it had a cause, since the cause itself cannot travel', function (): void {
    $failure = ConfigurationException::unresolvableBackUrlRoute('checkout.back', new RuntimeException('no route'));

    expect($failure->__serialize())->toBe([
        'message' => $failure->getMessage(),
        'file' => $failure->getFile(),
        'line' => $failure->getLine(),
        'chainDropped' => true,
    ]);
});

it('comes back from a round trip still pointing at where the mistake was found', function (): void {
    $failure = ConfigurationException::blankBackUrl();
    $restored = roundTrip($failure);

    expect($restored->getMessage())->toBe($failure->getMessage())
        ->and($restored->getFile())->toBe($failure->getFile())
        ->and($restored->getLine())->toBe($failure->getLine())
        ->and($restored->getFile())->not->toBe(__FILE__)
        ->and($restored->getPrevious())->toBeNull()
        ->and($restored->chainDropped())->toBeFalse();
});

it('says so when the round trip dropped a cause', function (): void {
    $restored = roundTrip(
        ConfigurationException::unresolvableBackUrlRoute('checkout.back', new RuntimeException('no route')),
    );

    expect($restored->getPrevious())->toBeNull()
        ->and($restored->chainDropped())->toBeTrue()
        ->and($restored->getMessage())->toContain('checkout.back');
});

it('degrades rather than throwing when the restored payload is unusable', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    $failure->__unserialize(['message' => 42, 'file' => [], 'line' => '17', 'chainDropped' => 'yes']);

    expect($failure->getMessage())->toBe('')
        ->and($failure->getFile())->toBe('')
        ->and($failure->getLine())->toBe(0)
        ->and($failure->chainDropped())->toBeFalse();
});

it('degrades rather than throwing when the restored payload is empty', function (): void {
    $failure = ConfigurationException::blankBackUrl();

    $failure->__unserialize([]);

    expect($failure->getMessage())->toBe('')
        ->and($failure->getFile())->toBe('')
        ->and($failure->getLine())->toBe(0)
        ->and($failure->chainDropped())->toBeFalse();
});
