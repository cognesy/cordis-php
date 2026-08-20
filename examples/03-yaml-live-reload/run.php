<?php

declare(strict_types=1);

use CordisPhp\Config\ExpressionEvaluator;
use CordisPhp\Config\ReconcileReport;
use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

/** @return array{mounted: list<string>, updated: list<string>, unchanged: list<string>, failed: list<string>} */
function reportSummary(ReconcileReport $report): array
{
    return [
        'mounted' => $report->mounted,
        'updated' => $report->updated,
        'unchanged' => $report->unchanged,
        'failed' => array_map(
            static fn ($failure): string => "$failure->path:$failure->reason",
            $report->failed,
        ),
    ];
}

function exampleFile(string $name): string
{
    $content = file_get_contents(__DIR__."/$name");
    if ($content === false) {
        throw new RuntimeException("Could not read $name.");
    }

    return $content;
}

$path = tempnam(sys_get_temp_dir(), 'cordis-yaml-example-');
if ($path === false) {
    throw new RuntimeException('Could not create a temporary YAML file.');
}

$events = [];
$plugins = new PluginRegistry();
$plugins->registerClosure(
    'logger',
    function (Context $context, mixed $config) use (&$events): Closure {
        $channel = is_array($config) && is_string($config['channel'] ?? null)
            ? $config['channel']
            : 'invalid';
        $context->provide('logger', "logger:$channel");
        $events[] = "logger:start:$channel";

        return function () use (&$events, $channel): void {
            $events[] = "logger:stop:$channel";
        };
    },
);
$plugins->registerClosure(
    'http-client',
    function (Context $context, mixed $config) use (&$events): Closure {
        $baseUri = is_array($config) && is_string($config['base_uri'] ?? null)
            ? $config['base_uri']
            : 'invalid';
        $logger = $context->get('logger');
        if (! is_string($logger)) {
            throw new RuntimeException('The logger service must be a string.');
        }
        $events[] = "client:start:$logger:$baseUri";

        return function () use (&$events, $baseUri): void {
            $events[] = "client:stop:$baseUri";
        };
    },
);

try {
    if (file_put_contents($path, exampleFile('composition.initial.yaml')) === false) {
        throw new RuntimeException('Could not seed the temporary YAML file.');
    }

    $runtime = new Runtime($plugins, new ExpressionEvaluator(['APP_CHANNEL' => 'demo']));
    $runtime->root()->provide('api-host', 'api.example.test');
    $loader = $runtime->yaml($path);

    $initial = $loader->reload();
    $unchanged = $loader->reloadIfChanged();

    if (file_put_contents($path, exampleFile('composition.updated.yaml')) === false) {
        throw new RuntimeException('Could not update the temporary YAML file.');
    }

    $changed = $loader->reloadIfChanged();
    if ($changed === null) {
        throw new RuntimeException('The changed YAML file was not reconciled.');
    }

    $initialSummary = reportSummary($initial);
    $changedSummary = reportSummary($changed);
    $live = $loader->live();
    $loader->dispose();
    $runtime->dispose();

    expectSame([
        'mounted' => ['platform', 'platform.logger', 'platform.client'],
        'updated' => [],
        'unchanged' => [],
        'failed' => [],
    ], $initialSummary, 'Initial YAML composition must mount');
    expectSame(null, $unchanged, 'An unchanged YAML document must be ignored');
    expectSame([
        'mounted' => [],
        'updated' => ['platform.client'],
        'unchanged' => ['platform.logger'],
        'failed' => [],
    ], $changedSummary, 'Only the changed client must restart');
    expectSame(['platform', 'platform.logger', 'platform.client'], $live, 'The live tree must stay stable');
    expectSame([
        'logger:start:demo',
        'client:start:logger:demo:https://api.example.test/v1',
        'client:stop:https://api.example.test/v1',
        'client:start:logger:demo:https://api.example.test/v2',
        'client:stop:https://api.example.test/v2',
        'logger:stop:demo',
    ], $events, 'Reconciliation must restart only the changed leaf');

    printResult([
        'scenario' => 'yaml-live-reload',
        'initial' => $initialSummary,
        'unchanged_reload' => $unchanged,
        'changed' => $changedSummary,
        'live_before_dispose' => $live,
        'events' => $events,
    ]);
} finally {
    unlink($path);
}
