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
    'model-client',
    function (Context $context, mixed $_config) use (&$events): Closure {
        $endpoint = $context->get('model-endpoint');
        if (! is_string($endpoint)) {
            throw new RuntimeException('The model endpoint must be a string.');
        }

        $events[] = "connect:$endpoint";

        return function () use (&$events, $endpoint): void {
            $events[] = "disconnect:$endpoint";
        };
    },
    ['model-endpoint'],
);

$runtime = new Runtime($plugins);
$client = $runtime->mount('model-client');
$states = [[
    'stage' => 'mounted',
    'state' => $client->state()->value,
    'missing' => $client->missing(),
]];

$releasePrimary = $runtime->root()->provide('model-endpoint', 'primary');
$states[] = [
    'stage' => 'primary-available',
    'state' => $client->state()->value,
    'missing' => $client->missing(),
];

$releasePrimary();
$states[] = [
    'stage' => 'primary-removed',
    'state' => $client->state()->value,
    'missing' => $client->missing(),
];

$runtime->root()->provide('model-endpoint', 'secondary');
$states[] = [
    'stage' => 'secondary-available',
    'state' => $client->state()->value,
    'missing' => $client->missing(),
];

$runtime->dispose();

expectSame([
    ['stage' => 'mounted', 'state' => 'pending', 'missing' => ['model-endpoint']],
    ['stage' => 'primary-available', 'state' => 'active', 'missing' => []],
    ['stage' => 'primary-removed', 'state' => 'pending', 'missing' => ['model-endpoint']],
    ['stage' => 'secondary-available', 'state' => 'active', 'missing' => []],
], $states, 'The client must react to endpoint availability');
expectSame([
    'connect:primary',
    'disconnect:primary',
    'connect:secondary',
    'disconnect:secondary',
], $events, 'The old client must close before a replacement starts');

printResult([
    'scenario' => 'dynamic-service-restart',
    'states' => $states,
    'events' => $events,
]);
