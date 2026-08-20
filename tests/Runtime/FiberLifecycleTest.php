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
});

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
