<?php

declare(strict_types=1);

use CordisPhp\Exception\DisposalException;
use CordisPhp\Exception\InactiveScopeException;
use CordisPhp\Exception\PluginException;
use CordisPhp\Runtime\EffectScope;

test('effects dispose in reverse registration order and handles are idempotent', function (): void {
    $events = [];
    $scope = new EffectScope('test');
    $first = $scope->defer(function () use (&$events): void {
        $events[] = 'first';
    }, 'first');
    $scope->defer(function () use (&$events): void {
        $events[] = 'second';
    }, 'second');

    $scope->dispose();
    $first();
    $scope->dispose();

    expect($events)->toBe(['second', 'first'])
        ->and($scope->isActive())->toBeFalse();
});

test('a parent scope releases child effects after its later registrations', function (): void {
    $events = [];
    $scope = new EffectScope('root');
    $child = $scope->child('child');
    $child->defer(function () use (&$events): void {
        $events[] = 'child';
    });
    $scope->defer(function () use (&$events): void {
        $events[] = 'parent';
    });

    $scope->dispose();

    expect($events)->toBe(['parent', 'child'])
        ->and($child->isActive())->toBeFalse();
});

test('a scope finishes every disposer and reports aggregated disposal failures', function (): void {
    $events = [];
    $scope = new EffectScope();
    $scope->defer(function () use (&$events): void {
        $events[] = 'first';
        throw new RuntimeException('first failure');
    });
    $scope->defer(function () use (&$events): void {
        $events[] = 'second';
        throw new RuntimeException('second failure');
    });

    try {
        $scope->dispose();
        throw new RuntimeException('Expected a disposal exception.');
    } catch (DisposalException $error) {
        expect($events)->toBe(['second', 'first'])
            ->and($error->errors)->toHaveCount(2)
            ->and($scope->isActive())->toBeFalse();
    }
});

test('effect accepts only cleanup closures or null', function (): void {
    $scope = new EffectScope();

    expect(fn (): Closure => $scope->effect(static fn (): string => 'not a cleanup'))
        ->toThrow(PluginException::class);

    $scope->dispose();

    expect(fn (): Closure => $scope->defer(static function (): void {
    }))->toThrow(InactiveScopeException::class);
});
