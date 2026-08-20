<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$events = [];
$plugins = new PluginRegistry();
$plugins->registerClosure(
    'tenant-worker',
    function (Context $context, mixed $_config) use (&$events): Closure {
        $logger = $context->get('logger');
        if (! is_string($logger)) {
            throw new RuntimeException('The tenant logger must be a string.');
        }
        $events[] = "worker:using:$logger";

        return static function (): void {
        };
    },
    ['logger'],
);
$plugins->registerClosure(
    'billing-adapter',
    static function (Context $_context, mixed $_config): null {
        return null;
    },
    ['billing-token'],
);

$runtime = new Runtime($plugins);
$runtime->root()->provide('logger', 'shared-logger');
$runtime->root()->provide('billing-token', 'root-only-token');

$worker = $runtime->mount('tenant-worker', isolate: ['billing-token']);
$billing = $runtime->mount('billing-adapter', isolate: ['billing-token']);

$snapshot = [
    'worker' => [
        'state' => $worker->state()->value,
        'missing' => $worker->missing(),
    ],
    'billing_adapter' => [
        'state' => $billing->state()->value,
        'missing' => $billing->missing(),
    ],
    'events' => $events,
];

$runtime->dispose();

expectSame([
    'worker' => ['state' => 'active', 'missing' => []],
    'billing_adapter' => ['state' => 'pending', 'missing' => ['billing-token']],
    'events' => ['worker:using:shared-logger'],
], $snapshot, 'Isolated plugins must retain permitted services and hide protected ones');

printResult([
    'scenario' => 'tenant-isolation',
    'snapshot' => $snapshot,
]);
