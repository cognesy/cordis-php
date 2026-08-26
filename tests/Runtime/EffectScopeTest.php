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

test('before-cleanup effects run first while preserving reverse order within each phase', function (): void {
    $events = [];
    $scope = new EffectScope('test');
    $scope->defer(function () use (&$events): void {
        $events[] = 'cleanup:first';
    });
    $scope->deferBeforeCleanup(function () use (&$events): void {
        $events[] = 'before:first';
    });
    $scope->defer(function () use (&$events): void {
        $events[] = 'cleanup:second';
    });
    $scope->deferBeforeCleanup(function () use (&$events): void {
        $events[] = 'before:second';
    });

    $scope->dispose();

    expect($events)->toBe([
        'before:second',
        'before:first',
        'cleanup:second',
        'cleanup:first',
    ]);
});

test('a manually released effect is pruned from an active scope', function (): void {
    $scope = new EffectScope('worker');
    $resource = new stdClass();
    $reference = WeakReference::create($resource);
    $release = $scope->defer(static function () use ($resource): void {
    });
    unset($resource);

    expect($reference->get())->not->toBeNull();

    $release();
    unset($release);
    gc_collect_cycles();

    expect($reference->get())->toBeNull()
        ->and($scope->isActive())->toBeTrue();
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
        $events[] = 'cleanup:first';
        throw new RuntimeException('cleanup first failure');
    });
    $scope->defer(function () use (&$events): void {
        $events[] = 'cleanup:second';
        throw new RuntimeException('cleanup second failure');
    });
    $scope->deferBeforeCleanup(function () use (&$events): void {
        $events[] = 'before:first';
        throw new RuntimeException('before cleanup first failure');
    });
    $scope->deferBeforeCleanup(function () use (&$events): void {
        $events[] = 'before:second';
        throw new RuntimeException('before cleanup second failure');
    });

    try {
        $scope->dispose();
        throw new RuntimeException('Expected a disposal exception.');
    } catch (DisposalException $error) {
        expect($events)->toBe([
            'before:second',
            'before:first',
            'cleanup:second',
            'cleanup:first',
        ])->and($error->errors)->toHaveCount(4)
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
