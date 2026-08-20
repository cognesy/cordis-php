<?php

declare(strict_types=1);

use CordisPhp\Event\EventBus;
use CordisPhp\Runtime\EffectScope;

test('event modes preserve the Cordis dispatch contracts', function (): void {
    $bus = new EventBus();
    $log = [];
    $bus->on('work', function (string $value) use (&$log): string {
        $log[] = "first:$value";

        return 'first-result';
    });
    $bus->on('work', function (string $value) use (&$log): string {
        $log[] = "second:$value";

        return 'second-result';
    });

    $bus->emit('work', 'emit');
    expect($log)->toBe(['first:emit', 'second:emit']);

    expect($bus->parallel('work', 'parallel'))->toBe(['first-result', 'second-result'])
        ->and($bus->serial('work', 'serial'))->toBe(['first-result', 'second-result']);

    $bus->on('bail', static fn (): null => null);
    $bus->on('bail', static fn (): string => 'answer');
    $bus->on('bail', static fn (): string => 'unreached');
    expect($bus->bail('bail'))->toBe('answer');

    $bus->on('waterfall', static fn (string $value): string => strtoupper($value));
    $bus->on('waterfall', static fn (string $value): string => "$value!");
    expect($bus->waterfall('waterfall', 'hello'))->toBe('HELLO!');
});

test('scoped and filtered listeners are removed exactly with their owner', function (): void {
    $bus = new EventBus();
    $scope = new EffectScope('listener-owner');
    $seen = [];

    $subscription = $bus->on(
        'notice',
        function (string $message) use (&$seen): void {
            $seen[] = $message;
        },
        $scope,
        static fn (string $_event, array $arguments): bool => ($arguments[0] ?? null) === 'accepted',
    );

    $bus->emit('notice', 'ignored');
    $bus->emit('notice', 'accepted');
    expect($seen)->toBe(['accepted'])
        ->and($subscription->isActive())->toBeTrue();

    $scope->dispose();
    $bus->emit('notice', 'accepted');

    expect($seen)->toBe(['accepted'])
        ->and($subscription->isActive())->toBeFalse();
});
