<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

final readonly class HttpClient
{
    /** @param array{retries: int, timeout_ms: int} $defaults */
    public function __construct(public array $defaults)
    {
    }
}

$seen = [];
$clients = [];
$plugins = new PluginRegistry();
$plugins->registerClosure('http-client', static function (Context $context, mixed $_config): null {
    $context->provide('http', new HttpClient(['retries' => 2, 'timeout_ms' => 100]));

    return null;
});
$plugins->registerClosure('fetch', static function (Context $context, mixed $config) use (&$seen, &$clients): null {
    $workload = is_array($config) ? ($config['workload'] ?? null) : null;
    $client = $context->get('http');
    if (! is_string($workload) || ! $client instanceof HttpClient) {
        throw new RuntimeException('Fetch requires a workload config and the shared HTTP client.');
    }

    $settings = [...$client->defaults, ...$context->interceptionFor('http')];
    $retries = $settings['retries'] ?? null;
    $timeout = $settings['timeout_ms'] ?? null;
    if (! is_int($retries) || ! is_int($timeout)) {
        throw new RuntimeException('HTTP policy must contain integer retries and timeout_ms values.');
    }

    $clients[] = $client;
    $seen[] = [
        'workload' => $workload,
        'retries' => $retries,
        'timeout_ms' => $timeout,
    ];

    return null;
}, ['http']);

$runtime = new Runtime($plugins);
$loader = $runtime->yaml(__DIR__.'/composition.yaml');
$report = $loader->reload();
$sharedClient = count($clients) === 2 && $clients[0] === $clients[1];

$snapshot = [
    'mounted' => $report->mounted,
    'effective_policies' => $seen,
    'shared_client' => $sharedClient,
];

$loader->dispose();
$runtime->dispose();

expectSame([
    'mounted' => ['http', 'bulk-export', 'payment-webhook'],
    'effective_policies' => [
        ['workload' => 'bulk-export', 'retries' => 2, 'timeout_ms' => 250],
        ['workload' => 'payment-webhook', 'retries' => 0, 'timeout_ms' => 100],
    ],
    'shared_client' => true,
], $snapshot, 'Interception must change local policy without replacing the shared service');

printResult([
    'scenario' => 'service-interception',
    ...$snapshot,
]);
