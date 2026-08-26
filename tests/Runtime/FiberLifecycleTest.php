<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Exception\DisposalException;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Fiber;
use CordisPhp\Runtime\FiberState;
use CordisPhp\Runtime\Runtime;
use CordisPhp\Tests\Support\ContractPlugin;

test('a fiber waits for requirements and restarts around changing services', function (): void {
    $events = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('consumer', function (Context $context, mixed $_config) use (&$events): Closure {
        $clock = $context->get('clock');
        $events[] = sprintf('start:%s', $clock);

        return function () use (&$events, $clock): void {
            $events[] = sprintf('stop:%s', $clock);
        };
    }, ['clock']);
    $runtime = new Runtime($plugins);

    $consumer = $runtime->mount('consumer');
    expect($consumer->state())->toBe(FiberState::Pending)
        ->and($consumer->missing())->toBe(['clock']);

    $releaseFirst = $runtime->root()->provide('clock', 'one');
    expect($consumer->state())->toBe(FiberState::Active)
        ->and($events)->toBe(['start:one']);

    $releaseFirst();
    expect($consumer->state())->toBe(FiberState::Pending)
        ->and($events)->toBe(['start:one', 'stop:one']);

    $runtime->root()->provide('clock', 'two');
    expect($consumer->state())->toBe(FiberState::Active)
        ->and($events)->toBe(['start:one', 'stop:one', 'start:two']);

    $runtime->dispose();
    expect($events)->toBe(['start:one', 'stop:one', 'start:two', 'stop:two']);
});

test('a provider unpublishes services and stops dependents before closing its resource', function (): void {
    $events = [];
    $consumer = null;
    $resource = new class () {
        public bool $closed = false;
    };
    $plugins = new PluginRegistry();
    $plugins->registerClosure('provider', function (Context $context, mixed $_config) use (
        &$consumer,
        &$events,
        $resource,
    ): Closure {
        $context->provide('resource', $resource);

        return function () use (&$consumer, &$events, $context, $resource): void {
            $events[] = [
                'event' => 'provider-cleanup',
                'published' => $context->has('resource'),
                'consumer' => $consumer?->state()->value,
            ];
            $resource->closed = true;
        };
    });
    $plugins->registerClosure('consumer', function (Context $context, mixed $_config) use (
        &$events,
        $resource,
    ): Closure {
        $context->get('resource');

        return function () use (&$events, $context, $resource): void {
            $events[] = [
                'event' => 'consumer-cleanup',
                'published' => $context->has('resource'),
                'resource-closed' => $resource->closed,
            ];
        };
    }, ['resource']);
    $runtime = new Runtime($plugins);

    $provider = $runtime->mount('provider');
    $consumer = $runtime->mount('consumer');
    $provider->dispose();

    expect($events)->toBe([
        [
            'event' => 'consumer-cleanup',
            'published' => false,
            'resource-closed' => false,
        ],
        [
            'event' => 'provider-cleanup',
            'published' => false,
            'consumer' => FiberState::Pending->value,
        ],
    ])->and($resource->closed)->toBeTrue()
        ->and($consumer->state())->toBe(FiberState::Pending)
        ->and($consumer->missing())->toBe(['resource']);
});

test('an isolated realm cannot see a deliberately hidden parent service', function (): void {
    $plugins = new PluginRegistry();
    $plugins->registerClosure('consumer', static function (Context $_context, mixed $_config): null {
        return null;
    }, ['secret']);
    $runtime = new Runtime($plugins);
    $runtime->root()->provide('secret', 'outside');

    $fiber = $runtime->mount('consumer', isolate: ['secret']);

    expect($fiber->state())->toBe(FiberState::Pending)
        ->and($fiber->missing())->toBe(['secret']);
})->group('isolation');

test('interception metadata is immutable and local to each mounted workload', function (): void {
    $seen = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('client', function (Context $context, mixed $_config) use (&$seen): null {
        $seen[] = $context->interceptionFor('http');

        return null;
    });
    $runtime = new Runtime($plugins);

    $fast = $runtime->mount('client', intercept: ['http' => ['timeout' => 1, 'retries' => 0]]);
    $durable = $runtime->mount('client', intercept: ['http' => ['timeout' => 10, 'retries' => 3]]);

    expect($seen)->toBe([
        ['timeout' => 1, 'retries' => 0],
        ['timeout' => 10, 'retries' => 3],
    ])->and($fast->context()->interceptionFor('http'))->toBe(['timeout' => 1, 'retries' => 0])
        ->and($durable->context()->interceptionFor('http'))->toBe(['timeout' => 10, 'retries' => 3])
        ->and($runtime->root()->interceptionFor('http'))->toBe([]);
})->group('interception');

test('runtime fibers expose active and failed health without retaining disposed fibers', function (): void {
    $plugins = new PluginRegistry();
    $plugins->registerClosure('healthy', static function (Context $_context, mixed $_config): null {
        return null;
    });
    $plugins->registerClosure('failed', static function (Context $_context, mixed $_config): never {
        throw new RuntimeException('startup failed');
    });
    $runtime = new Runtime($plugins);

    $healthy = $runtime->mount('healthy');
    $failed = $runtime->mount('failed');

    expect(array_map(
        static fn (Fiber $fiber): array => [$fiber->label(), $fiber->state()->value, $fiber->error()?->getMessage()],
        $runtime->fibers(),
    ))->toBe([
        ['healthy', 'active', null],
        ['failed', 'failed', 'startup failed'],
    ]);

    $healthy->dispose();

    expect($runtime->fibers())->toBe([$failed]);

    $runtime->dispose();

    expect($runtime->fibers())->toBe([]);
})->group('health');

test('restarting a long-lived fiber releases every previous attempt scope', function (): void {
    $plugins = new PluginRegistry();
    $plugins->registerClosure('worker', static function (Context $_context, mixed $_config): null {
        return null;
    });
    $runtime = new Runtime($plugins);
    $runtime->root()->provide('clock', 'stable');
    $fiber = $runtime->mount('worker');
    $previousAttempts = [];

    for ($revision = 0; $revision < 500; ++$revision) {
        $previousScope = $fiber->scope();
        $previousAttempts[] = WeakReference::create($previousScope);
        $fiber->update(['revision' => $revision]);
        unset($previousScope);
    }
    gc_collect_cycles();

    expect(array_filter(
        $previousAttempts,
        static fn (WeakReference $reference): bool => $reference->get() !== null,
    ))->toBe([])
        ->and($fiber->state())->toBe(FiberState::Active);
})->group('reconciliation');

test('a child mounted through an extended context remains owned by its parent fiber', function (): void {
    $plugins = new PluginRegistry();
    $children = [];
    $plugins->registerClosure('child', static function (Context $_context, mixed $_config): null {
        return null;
    });
    $plugins->registerClosure('parent', function (Context $context, mixed $_config) use (&$children): null {
        $children[] = $context->extend(['feature' => 'demo'])->plugin('child');

        return null;
    });
    $runtime = new Runtime($plugins);

    $parent = $runtime->mount('parent');

    expect($parent->state())->toBe(FiberState::Active)
        ->and($children)->toHaveCount(1)
        ->and($children[0])->toBeInstanceOf(Fiber::class)
        ->and($children[0]->state())->toBe(FiberState::Active);

    $parent->dispose();

    expect($children[0]->state())->toBe(FiberState::Disposed);
});

test('class contracts supply validation and requirements to a registered plugin', function (): void {
    ContractPlugin::reset();
    $plugins = new PluginRegistry();
    $plugins->registerClass('contract', ContractPlugin::class);
    $runtime = new Runtime($plugins);

    $fiber = $runtime->mount('contract', ['message' => 'hello']);
    expect($fiber->state())->toBe(FiberState::Pending);

    $runtime->root()->provide('clock', '10:30');

    expect($fiber->state())->toBe(FiberState::Active)
        ->and(ContractPlugin::$applied)->toBe([
            ['clock' => '10:30', 'config' => ['message' => 'hello']],
        ]);
});

test('disposing a fiber removes it from the runtime registry', function (): void {
    $plugins = new PluginRegistry();
    $plugins->registerClosure('ephemeral', static function (Context $_context, mixed $_config): null {
        return null;
    });
    $runtime = new Runtime($plugins);

    $fiber = $runtime->mount('ephemeral');
    expect($runtime->fibers())->toBe([$fiber]);

    $fiber->dispose();

    expect($fiber->state())->toBe(FiberState::Disposed)
        ->and($runtime->fibers())->toBe([]);
});

test('a cleanup failure becomes a recoverable failed fiber rather than an unloading leak', function (): void {
    $plugins = new PluginRegistry();
    $starts = 0;
    $plugins->registerClosure('fragile', function (Context $_context, mixed $_config) use (&$starts): Closure {
        ++$starts;

        return static function (): void {
            throw new RuntimeException('cleanup failed');
        };
    });
    $runtime = new Runtime($plugins);
    $fiber = $runtime->mount('fragile');

    $fiber->update(['revision' => 1]);
    expect($fiber->state())->toBe(FiberState::Failed)
        ->and($fiber->error())->toBeInstanceOf(DisposalException::class);

    $fiber->update(['revision' => 2]);
    expect($fiber->state())->toBe(FiberState::Active)
        ->and($starts)->toBe(2);
});
