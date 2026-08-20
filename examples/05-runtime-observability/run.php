<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Fiber;
use CordisPhp\Runtime\FiberStatusChange;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

/** @return list<array{plugin: string, state: string, missing: list<string>, error: ?string}> */
function healthSnapshot(Runtime $runtime): array
{
    return array_map(static function (Fiber $fiber): array {
        return [
            'plugin' => $fiber->label(),
            'state' => $fiber->state()->value,
            'missing' => $fiber->missing(),
            'error' => $fiber->error()?->getMessage(),
        ];
    }, $runtime->fibers());
}

$plugins = new PluginRegistry();
$plugins->registerClosure(
    'worker',
    static function (Context $context, mixed $_config): Closure {
        $clock = $context->get('clock');
        if (! is_string($clock)) {
            throw new RuntimeException('The clock service must be a string.');
        }

        return static function (): void {
        };
    },
    ['clock'],
);
$plugins->registerClosure('trace-exporter', static function (Context $_context, mixed $_config): null {
    throw new RuntimeException('collector unreachable');
});

$runtime = new Runtime($plugins);
$transitions = [];
$unsubscribe = $runtime->onStatus(function (FiberStatusChange $change) use (&$transitions): void {
    $transitions[] = sprintf(
        '%s:%s->%s',
        $change->fiber->label(),
        $change->previous->value,
        $change->current->value,
    );
});

$runtime->mount('worker');
$runtime->root()->provide('clock', '12:00');
$runtime->mount('trace-exporter');

$beforeShutdown = healthSnapshot($runtime);
$runtime->dispose();
$unsubscribe();

expectSame([
    ['plugin' => 'worker', 'state' => 'active', 'missing' => [], 'error' => null],
    ['plugin' => 'trace-exporter', 'state' => 'failed', 'missing' => [], 'error' => 'collector unreachable'],
], $beforeShutdown, 'The health snapshot must expose active and failed capabilities');
expectSame([
    'worker:pending->loading',
    'worker:loading->active',
    'trace-exporter:pending->loading',
    'trace-exporter:loading->failed',
    'trace-exporter:failed->unloading',
    'trace-exporter:unloading->disposed',
    'worker:active->unloading',
    'worker:unloading->disposed',
], $transitions, 'Lifecycle transitions must form a stable telemetry stream');
expectSame([], $runtime->fibers(), 'Shutdown must remove every fiber from the health snapshot');

printResult([
    'scenario' => 'runtime-observability',
    'health_before_shutdown' => $beforeShutdown,
    'transitions' => $transitions,
    'health_after_shutdown' => $runtime->fibers(),
]);
