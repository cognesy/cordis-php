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
    'notification-bridge',
    function (Context $context, mixed $_config) use (&$events): Closure {
        $events[] = 'bridge:started';
        $context->provide('notifier', 'ready');
        $context->on('notification.sent', function (string $message) use (&$events): void {
            $events[] = "sent:$message";
        });

        return function () use (&$events): void {
            $events[] = 'bridge:closed';
        };
    },
);

$runtime = new Runtime($plugins);
$bridge = $runtime->mount('notification-bridge');
$runtime->events()->emit('notification.sent', 'invoice-42');

$beforeDispose = [
    'state' => $bridge->state()->value,
    'service_available' => $runtime->root()->has('notifier'),
    'events' => $events,
];

$bridge->dispose();
$runtime->events()->emit('notification.sent', 'ignored-after-dispose');

$afterDispose = [
    'state' => $bridge->state()->value,
    'service_available' => $runtime->root()->has('notifier'),
    'active_fibers' => count($runtime->fibers()),
    'events' => $events,
];

expectSame([
    'state' => 'active',
    'service_available' => true,
    'events' => ['bridge:started', 'sent:invoice-42'],
], $beforeDispose, 'Bridge must be live before disposal');
expectSame([
    'state' => 'disposed',
    'service_available' => false,
    'active_fibers' => 0,
    'events' => ['bridge:started', 'sent:invoice-42', 'bridge:closed'],
], $afterDispose, 'Bridge disposal must release every scoped effect');

printResult([
    'scenario' => 'scoped-lifecycle',
    'before_dispose' => $beforeDispose,
    'after_dispose' => $afterDispose,
]);
